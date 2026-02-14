#!/usr/bin/env python3

import time
import json
import psutil
import requests
import subprocess
import os
from datetime import datetime

def get_metrics():
    """Сбор системных метрик"""
    cpu_percent = psutil.cpu_percent(interval=1)
    memory = psutil.virtual_memory()
    disk_usage = psutil.disk_usage('/')

    # Получаем сетевую статистику
    try:
        net_io = psutil.net_io_counters()
    except:
        net_io = None

    return {
        'cpu_load': cpu_percent,
        'ram_used': memory.percent,
        'disk_used': disk_usage.percent
    }

def get_top_processes(process_type='cpu'):
    """Сбор топ-5 процессов по CPU или RAM"""
    processes = []
    
    try:
        for proc in psutil.process_iter(['pid', 'name', 'cpu_percent', 'memory_percent']):
            try:
                info = proc.info
                if info['cpu_percent'] is None or info['memory_percent'] is None:
                    continue
                    
                processes.append({
                    'pid': info['pid'],
                    'name': info['name'],
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
    """Сбор списка сервисов через systemctl"""
    try:
        result = subprocess.run(['systemctl', 'list-units', '--type=service', '--no-pager', '--all'],
                           capture_output=True, text=True, timeout=5)
        services = []

        for line in result.stdout.split('\n')[1:]:  # Пропускаем заголовок
            if not line.strip():
                continue

            parts = line.split(None, 4)  # Разделяем на 5 частей максимум
            if len(parts) >= 4:
                service_name = parts[0]
                load_state = parts[1] if len(parts) > 1 else ''
                active_state = parts[2] if len(parts) > 2 else ''
                sub_state = parts[3] if len(parts) > 3 else ''

                # Определяем статус сервиса
                if active_state == 'active':
                    status = 'running'
                elif active_state in ['inactive', 'failed']:
                    status = 'stopped'
                else:
                    status = 'unknown'

                services.append({
                    'name': service_name,
                    'status': status,
                    'load_state': load_state,
                    'active_state': active_state,
                    'sub_state': sub_state
                })

        return services

    except Exception as e:
        print(f"Ошибка получения сервисов: {e}")
        return []

def send_metrics():
    """Отправка метрик на сервер"""
    with open('/opt/server-monitor-agent/config.json', 'r') as f:
        config = json.load(f)

    token = config['token']
    api_url = config['api_url']

    # Собираем метрики
    metrics = get_metrics()
    
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
