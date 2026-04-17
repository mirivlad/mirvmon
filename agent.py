#!/usr/bin/env python3

import time
import json
import psutil
import requests
import subprocess
import os
from datetime import datetime

# Скипаем виртуальные и служебные интерфейсы
SKIP_INTERFACE_PREFIXES = ('lo', 'docker', 'veth', 'br-', 'tun', 'tap', 'wg', 'virbr', 'vmnet', 'vmxnet')

# Храним предыдущие значения net_io для расчёта дельты
_prev_net_io = {}


def _is_real_interface(name, stats):
    for prefix in SKIP_INTERFACE_PREFIXES:
        if name.startswith(prefix):
            return False
    if not stats.isup:
        return False
    return True


def get_network_metrics(interval=60):
    global _prev_net_io
    metrics = {}
    try:
        counters = psutil.net_io_counters(pernic=True)
        stats = psutil.net_if_stats()
        now = __import__('time').time()
        
        # Инициализируем интерфейсы которые ещё не видели
        for name, counter in counters.items():
            if name not in stats:
                continue
            if not _is_real_interface(name, stats[name]):
                continue
            if name not in _prev_net_io:
                _prev_net_io[name] = {'rx': counter.bytes_recv, 'tx': counter.bytes_sent, 'time': now}
        
        # Рассчитываем метрики
        for name, counter in counters.items():
            if name not in stats:
                continue
            if not _is_real_interface(name, stats[name]):
                continue
            
            speed_mbps = stats[name].speed if stats[name].speed > 0 else 1000
            speed_bps = speed_mbps * 1000000 / 8
            
            prev = _prev_net_io[name]
            elapsed = now - prev['time']
            
            if elapsed >= 1:  # Минимум 1 секунда
                rx_delta = counter.bytes_recv - prev['rx']
                tx_delta = counter.bytes_sent - prev['tx']
                if rx_delta >= 0 and tx_delta >= 0:
                    rx_pct = min((rx_delta / elapsed) / speed_bps * 100, 100.0)
                    tx_pct = min((tx_delta / elapsed) / speed_bps * 100, 100.0)
                    iface_key = name.replace('-', '_')
                    metrics[f'net_in_{iface_key}'] = round(rx_pct, 2)
                    metrics[f'net_out_{iface_key}'] = round(tx_pct, 2)
            
            _prev_net_io[name] = {'rx': counter.bytes_recv, 'tx': counter.bytes_sent, 'time': now}
    except Exception as e:
        print(f'Ошибка сбора сетевых метрик: {e}')
    return metrics


def _is_real_partition(mountpoint, fstype):
    """Проверяем что раздел реальный (не tmpfs, docker, snap и т.д.)"""
    skip_fstypes = {'tmpfs', 'devtmpfs', 'overlay', 'squashfs', 'snap',
                    'devpts', 'proc', 'sysfs', 'cgroup', 'cgroup2',
                    'pstore', 'hugetlbfs', 'mqueue', 'debugfs',
                    'tracefs', 'bpf', 'fusectl', 'configfs',
                    'securityfs', 'ramfs'}
    skip_mounts = {'/run', '/run/lock', '/sys', '/proc', '/dev',
                   '/dev/shm', '/dev/pts', '/sys/fs/cgroup'}

    if fstype in skip_fstypes:
        return False
    if mountpoint in skip_mounts:
        return False
    # Пропускаем EFI — слишком маленький, не информативен
    if mountpoint == '/boot/efi':
        return False
    return True


def get_disk_metrics():
    """Собираем метрики диска для реальных разделов"""
    metrics = {}
    skip_fstypes = {'tmpfs', 'devtmpfs', 'overlay', 'squashfs', 'snap',
                    'devpts', 'proc', 'sysfs', 'cgroup', 'cgroup2',
                    'pstore', 'hugetlbfs', 'mqueue', 'debugfs',
                    'tracefs', 'bpf', 'fusectl', 'configfs',
                    'securityfs', 'ramfs'}
    skip_mounts = {'/run', '/run/lock', '/sys', '/proc', '/dev',
                   '/dev/shm', '/dev/pts', '/sys/fs/cgroup'}

    # Собираем реальные разделы с их устройствами
    partitions = []
    for part in psutil.disk_partitions(all=False):
        if part.fstype in skip_fstypes:
            continue
        if part.mountpoint in skip_mounts:
            continue
        if part.mountpoint == '/boot/efi':
            continue
        try:
            usage = psutil.disk_usage(part.mountpoint)
            partitions.append({
                'mountpoint': part.mountpoint,
                'device': part.device,
                'usage': usage
            })
        except (PermissionError, OSError):
            pass

    # Определяем уникальные устройства
    devices = {}
    for p in partitions:
        dev = p['device']
        if dev not in devices:
            devices[dev] = []
        devices[dev].append(p)

    # Если одно устройство - только /
    # Если несколько - для каждого отдельного устройства
    if len(devices) == 1:
        # Один диск - только корень
        for p in partitions:
            if p['mountpoint'] == '/':
                metrics['disk_used_root'] = round(p['usage'].percent, 1)
                metrics['disk_total_gb_root'] = round(p['usage'].total / (1024**3), 1)
                metrics['disk_used'] = round(p['usage'].percent, 1)
                break
    else:
        # Несколько устройств - собираем для каждого
        for dev, parts in devices.items():
            for p in parts:
                mp = p['mountpoint']
                name = mp.strip('/').replace('/', '_') or 'root'
                metrics[f'disk_used_{name}'] = round(p['usage'].percent, 1)
                metrics[f'disk_total_gb_{name}'] = round(p['usage'].total / (1024**3), 1)
                if mp == '/':
                    metrics['disk_used'] = round(p['usage'].percent, 1)

    return metrics


def get_metrics():
    """Сбор системных метрик"""
    cpu_percent = psutil.cpu_percent(interval=1)
    memory = psutil.virtual_memory()

    # Дисковые метрики для всех реальных разделов
    disk_metrics = get_disk_metrics()

    result = {
        'cpu_load': cpu_percent,
        'ram_used': memory.percent,
    }
    result.update(disk_metrics)

    # Аптайм сервера (в секундах)
    import time
    result['uptime'] = int(time.time() - psutil.boot_time())

    # Метрики использования сети
    net_metrics = get_network_metrics()
    result.update(net_metrics)
    
    # RAM total GB
    result["ram_total_gb"] = round(memory.total / (1024**3), 1)

    if net_metrics:
        print(f"  Сетевые метрики: {net_metrics}")

    return result

def get_top_processes(process_type='cpu'):
    """Сбор топ-5 процессов по CPU или RAM"""
    processes = []
    
    try:
        for proc in psutil.process_iter(['pid', 'name', 'cpu_percent', 'memory_percent', 'cmdline']):
            try:
                info = proc.info
                if info['cpu_percent'] is None or info['memory_percent'] is None:
                    continue
                    
                cmdline = info.get('cmdline') or []
                if cmdline:
                    full_cmd = ' '.join(cmdline)
                    cmd_display = full_cmd[:120] + ('...' if len(full_cmd) > 120 else '')
                else:
                    cmd_display = info.get('name', '')
                    
                processes.append({
                    'pid': info['pid'],
                    'name': info['name'],
                    'cmdline': cmd_display,
                    'value': round(info[process_type + '_percent'], 1)
                })
            except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.ZombieProcess):
                continue
        
        # Сортируем по значению и берем топ-5
        if process_type == 'cpu':
            key = 'value'
        else:  # memory
            key = 'value'
            
        processes.sort(key=lambda x: x[key], reverse=True)
        top_5 = processes[:5]
        
        return top_5
        
    except Exception as e:
        print(f"Ошибка получения топ-процессов ({process_type}): {e}")
        return []

def get_services():
    """Сбор списка сервисов через systemctl (list-unit-files + list-units)"""
    try:
        # 1. Получаем полный список всех сервисов (включая dead/выгруженные)
        res_files = subprocess.run(['systemctl', 'list-unit-files', '--type=service', '--no-pager'],
                                   capture_output=True, text=True, timeout=10)
        
        # 2. Получаем текущие статусы активных/загруженных сервисов
        res_units = subprocess.run(['systemctl', 'list-units', '--type=service', '--all', '--no-pager'],
                                   capture_output=True, text=True, timeout=10)
        
        # Парсим unit-files (список всех сервисов)
        all_services = {}
        for line in res_files.stdout.split('\n'):
            parts = line.split()
            if parts and parts[0].endswith('.service'):
                all_services[parts[0]] = {'name': parts[0], 'enabled_state': parts[1] if len(parts) > 1 else 'unknown'}
                
        # Парсим list-units (текущее состояние)
        running_states = {}
        for line in res_units.stdout.split('\n'):
            parts = line.split(None, 4)
            if len(parts) >= 4 and parts[0].endswith('.service'):
                running_states[parts[0]] = {
                    'load_state': parts[1],
                    'active_state': parts[2],
                    'sub_state': parts[3]
                }
        
        services = []
        # Объединяем: берем все сервисы из list-unit-files
        for svc_name in all_services.keys():
            if svc_name in running_states:
                state = running_states[svc_name]
                load = state['load_state']
                active = state['active_state']
                sub = state['sub_state']
            else:
                # Сервис есть в системе, но не загружен (dead)
                load = 'loaded' # Обычно loaded, если файл юнита есть
                active = 'inactive'
                sub = 'dead'
            
            if active == 'active':
                status = 'running'
            elif active in ['inactive', 'failed', 'deactivating']:
                status = 'stopped'
            else:
                status = 'unknown'
                
            services.append({
                'name': svc_name,
                'status': status,
                'load_state': load,
                'active_state': active,
                'sub_state': sub
            })
            
        return services
    except Exception as e:
        print(f"Ошибка получения списка сервисов: {e}")
        return []


def get_temperatures():
    """Сбор температур (CPU, GPU, Disks)"""
    temps = {}
    
    # 1. CPU via psutil
    try:
        sensors = psutil.sensors_temperatures()
        if sensors:
            cpu_temps = []
            for name, entries in sensors.items():
                if name.lower() in ['coretemp', 'k10temp', 'zenpower']:
                    for entry in entries:
                        if entry.current:
                            cpu_temps.append(entry.current)
            if cpu_temps:
                temps['temp_cpu'] = max(cpu_temps)
            elif not temps:
                for entries in sensors.values():
                    for entry in entries:
                        if entry.current:
                            cpu_temps.append(entry.current)
                if cpu_temps:
                    temps['temp_cpu'] = max(cpu_temps)
    except Exception:
        pass

    # 2. Disks via smartctl
    try:
        import glob
        disks = glob.glob('/dev/sd[a-z]') + glob.glob('/dev/nvme[0-9]n1')
        for disk in disks:
            res = subprocess.run(['smartctl', '-n', 'standby', '-A', disk], 
                                 capture_output=True, text=True, timeout=5)
            if res.returncode == 0 and 'STANDBY' not in res.stdout.upper():
                for line in res.stdout.split('\n'):
                    if 'Temperature' in line:
                        parts = line.split()
                        # Ищем число в диапазоне 10-100
                        for p in reversed(parts):
                            try:
                                v = int(p)
                                if 10 < v < 100:
                                    disk_name = disk.split('/')[-1]
                                    temps[f'temp_disk_{disk_name}'] = float(v)
                                    break
                            except ValueError:
                                pass
    except Exception:
        pass

    # 3. GPU via nvidia-smi
    try:
        res = subprocess.run(['nvidia-smi', '--query-gpu=temperature.gpu', '--format=csv,noheader'], 
                             capture_output=True, text=True, timeout=5)
        if res.returncode == 0:
            lines = res.stdout.strip().split('\n')
            if len(lines) == 1:
                try:
                    temps['temp_gpu'] = float(lines[0])
                except: pass
            else:
                for i, line in enumerate(lines):
                    try:
                        temps[f'temp_gpu_{i}'] = float(line)
                    except: pass
    except Exception:
        pass
        
    return temps

def send_metrics():
    """Отправка метрик на сервер"""
    with open('/opt/server-monitor-agent/config.json', 'r') as f:
        config = json.load(f)

    token = config['token']
    api_url = config['api_url']

    # Собираем метрики
    metrics = get_metrics()
    temps = get_temperatures()
    metrics.update(temps)
    
    # Собираем топ-процессы
    top_cpu = get_top_processes('cpu')
    top_ram = get_top_processes('memory')
    
    # Добавляем топ-процессы как метрики
    if top_cpu:
        metrics['top_cpu_proc'] = json.dumps(top_cpu)
    if top_ram:
        metrics['top_ram_proc'] = json.dumps(top_ram)

    # Собираем сервисы
    services = get_services()

    # Формируем данные для отправки
    data = {
        'token': token,
        'metrics': metrics,
        'services': services
    }

    # Отправляем на сервер
    try:
        response = requests.post(api_url, json=data, timeout=5)
        if response.status_code == 200:
            print(f"{datetime.now()} - Метрики успешно отправлены")
        else:
            print(f"{datetime.now()} - Ошибка отправки: {response.status_code}")
    except Exception as e:
        print(f"{datetime.now()} - Ошибка соединения: {e}")

def main():
    """Главная функция агента"""
    print(f"{datetime.now()} - Агент мониторинга запущен")

    while True:
        send_metrics()

        # Ждем указанный интервал
        with open('/opt/server-monitor-agent/config.json', 'r') as f:
            config = json.load(f)
            interval = config.get('interval_seconds', 60)

        time.sleep(interval)

if __name__ == '__main__':
    main()
