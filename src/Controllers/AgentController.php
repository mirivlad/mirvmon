<?php
// src/Controllers/AgentController.php

namespace App\Controllers;

use App\Models\Model;
use App\Utils\EncryptionHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AgentController extends Model
{
    public function generateInstallScript(Request $request, Response $response, $args)
    {
        $queryParams = $request->getQueryParams();
        $token = $queryParams['token'] ?? null;
        $server_id = $queryParams['server_id'] ?? null;

        // Если передан server_id, получаем оригинальный токен из зашифрованного
        if (!empty($server_id) && empty($token)) {
            $stmt = $this->pdo->prepare("SELECT encrypted_token FROM agent_tokens WHERE server_id = :server_id LIMIT 1");
            $stmt->execute([':server_id' => $server_id]);
            $result = $stmt->fetch();

            if ($result && !empty($result['encrypted_token'])) {
                $token = EncryptionHelper::decrypt($result['encrypted_token']);
            }
        }

        if (empty($token)) {
            $response->getBody()->write('Token is required');
            return $response->withStatus(400);
        }

        $apiUrl = 'https://mon.mirv.top/api/v1/metrics';

        // Формируем скрипт с прямой подстановкой значений
        $script = "#!/bin/bash

# Скрипт установки агента мониторинга с поддержкой сервисов
# Сгенерировано автоматически

TOKEN='" . $token . "'
API_URL='" . $apiUrl . "'

echo 'Установка агента мониторинга...'

# Проверяем наличие Python3
if ! command -v python3 &> /dev/null; then
    echo 'Установка Python3...'
    apt-get update
    apt-get install -y python3 python3-pip lm-sensors smartmontools
fi

# Устанавливаем psutil
pip3 install psutil || easy_install3 psutil

# Создаем директорию для агента
mkdir -p /opt/server-monitor-agent
cd /opt/server-monitor-agent

# Создаем конфигурационный файл
echo '{
    \\\"token\\\": \\\"" . $token . "\\\"\\,
    \\\"api_url\\\": \\\"" . $apiUrl . "\\\"\\,
    \\\"interval_seconds\\\": 60
}' > config.json

# Создаем Python-скрипт агента с поддержкой сервисов
cat > agent.py << 'PYTHON_EOF'
import time
import json
import psutil
import requests
import subprocess
import os
from datetime import datetime

def get_metrics():
    \\\"\\\"\\\"Сбор системных метрик\\\"\\\"\\\"
    cpu_percent = psutil.cpu_percent(interval=1)
    memory = psutil.virtual_memory()
    disk_usage = psutil.disk_usage('/')

    # Получаем сетевую статистику
    try:
        net_io = psutil.net_io_counters()
    except:
        net_io = None

    metrics = {
        'cpu_load': round(cpu_percent, 2),
        'ram_used': round(memory.percent, 2),
        'disk_used': round((disk_usage.used / disk_usage.total) * 100, 2),
        'network_in': round((net_io.bytes_recv / (1024*1024)) if net_io else 0, 2),  # MB
        'network_out': round((net_io.bytes_sent / (1024*1024)) if net_io else 0, 2)  # MB
    }

    return metrics

def get_services():
    \\\"\\\"\\\"Сбор статусов всех сервисов\\\"\\\"\\\"
    services = []

    try:
        # Получаем список всех сервисов
        result = subprocess.run(
            ['systemctl', 'list-units', '--type=service', '--all', '--no-pager'],
            capture_output=True,
            text=True,
            timeout=30
        )

        lines = result.stdout.strip().split('\\n')

        for line in lines[1:]:  # Пропускаем заголовок
            parts = line.split()
            if len(parts) >= 4:
                service_name = parts[0].replace('.service', '')
                load_state = parts[1]
                active_state = parts[2]
                sub_state = parts[3] if len(parts) > 3 else ''

                # Определяем статус сервиса
                if active_state == 'active' and sub_state == 'running':
                    status = 'running'
                elif active_state in ['inactive', 'failed', 'dead']:
                    status = 'stopped'
                else:
                    status = 'unknown'

                # Пропускаем системные сервисы без .service в имени
                if not service_name.startswith('system-'):
                    services.append({
                        'name': service_name,
                        'status': status,
                        'load_state': load_state,
                        'active_state': active_state,
                        'sub_state': sub_state
                    })

    except Exception as e:
        print(f'Ошибка при получении списка сервисов: {e}')

    return services

def get_config_from_server():
    \\\"\\\"\\\"Получение конфигурации с сервера\\\"\\\"\\\"
    try:
        with open('config.json', 'r') as f:
            config = json.load(f)
    except Exception as e:
        print(f'Ошибка чтения конфига: {e}')
        return None

    token = config.get('token')
    if not token:
        print('Отсутствует токен в конфиге')
        return None

    # Определяем URL для получения конфигурации
    server_id = token.split('-')[0] if '-' in token else '1'

    try:
        response = requests.get(
            f\\\"\\\"{config['api_url']}/agent/{server_id}/config\\\"\\\"\\\",
            headers={'Authorization': f'Bearer {token}'},
            timeout=10
        )

        if response.status_code == 200:
            server_config = response.json()

            # Обновляем локальный конфиг
            config['interval_seconds'] = server_config.get('interval_seconds', config['interval_seconds'])
            config['monitor_services'] = server_config.get('monitor_services', config.get('monitor_services', []))

            # Сохраняем обновленный конфиг
            with open('config.json', 'w') as f:
                json.dump(config, f, indent=2)

            return config
        else:
            print(f'Ошибка получения конфига с сервера: {response.status_code}')
            return config

    except Exception as e:
        print(f'Ошибка подключения к серверу: {e}')
        return config

def send_metrics(config, metrics, services):
    \\\"\\\"\\\"Отправка метрик и сервисов на сервер\\\"\\\"\\\"
    data = {
        'token': config['token'],
        'metrics': metrics,
        'services': services
    }

    try:
        response = requests.post(
            config['api_url'],
            json=data,
            timeout=10
        )
        if response.status_code == 200:
            print(f'{datetime.now().strftime(\\\"%Y-%m-%d %H:%M:%S\\\")} - Метрики отправлены успешно')
            return True
        else:
            print(f'Ошибка отправки метрик: {response.status_code}')
            return False
    except Exception as e:
        print(f'Ошибка отправки метрик: {e}')
        return False

def main():
    \\\"\\\"\\\"Главная функция агента\\\"\\\"\\\"
    print('Агент мониторинга запущен...')

    # Загружаем конфигурацию
    config = get_config_from_server()
    if not config:
        print('Не удалось загрузить конфигурацию')
        return

    interval = config.get('interval_seconds', 60)
    monitor_services = config.get('monitor_services', [])

    print(f'Интервал отправки: {interval} сек')
    print(f'Мониторинг сервисов: {\\\"включен\\\" if monitor_services else \\\"все сервисы\\\"}')

    last_config_update = time.time()

    while True:
        try:
            # Проверяем нужно ли обновить конфиг (каждые 5 минут)
            if time.time() - last_config_update > 300:
                print('Проверка обновления конфигурации...')
                config = get_config_from_server()
                last_config_update = time.time()

                # Обновляем интервал если изменился
                interval = config.get('interval_seconds', 60)
                monitor_services = config.get('monitor_services', [])

            # Собираем метрики
            metrics = get_metrics()

            # Собираем сервисы
            services = get_services()

            # Если указаны конкретные сервисы для мониторинга - фильтруем
            if monitor_services:
                services = [s for s in services if s['name'] in monitor_services]
                print(f'Мониторинг {len(services)} сервисов: {[s[\\\"name\\\"] for s in services]}')

            # Отправляем данные
            success = send_metrics(config, metrics, services)

            if success:
                print(f'Метрики отправлены: CPU={metrics[\\\"cpu_load\\\"]}%, RAM={metrics[\\\"ram_used\\\"]}%, Disk={metrics[\\\"disk_used\\\"]}%')
            else:
                print('Ошибка отправки метрик')

            # Ждем указанный интервал
            time.sleep(interval)

        except KeyboardInterrupt:
            print('Агент остановлен')
            break
        except Exception as e:
            print(f'Ошибка: {e}')
            time.sleep(10)

if __name__ == '__main__':
    main()
PYTHON_EOF

# Создаем systemd сервис
cat > /etc/systemd/system/server-monitor-agent.service << 'SERVICE_EOF'
[Unit]
Description=Server Monitor Agent
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/server-monitor-agent
ExecStart=/usr/bin/python3 /opt/server-monitor-agent/agent.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
SERVICE_EOF

# Делаем скрипт исполняемым
chmod +x agent.py

# Перезагружаем systemd
systemctl daemon-reload

# Включаем автозапуск сервиса
systemctl enable server-monitor-agent

# Запускаем сервис
systemctl start server-monitor-agent

echo 'Агент мониторинга установлен и запущен!'
echo 'Статус сервиса:'
systemctl status server-monitor-agent
";

        $response->getBody()->write($script);
        return $response
            ->withHeader('Content-Type', 'application/x-shellscript')
            ->withHeader('Content-Disposition', 'attachment; filename="install.sh"');
    }

    public function getConfig(Request $request, Response $response, $args)
    {
        $serverId = $args['id'];

        // Получаем конфигурацию агента
        $stmt = $this->pdo->prepare("
            SELECT interval_seconds, monitor_services, enabled
            FROM agent_configs
            WHERE server_id = :server_id
        ");
        $stmt->execute([':server_id' => $serverId]);
        $config = $stmt->fetch();

        if (!$config) {
            // Если конфигурации нет - создаем с дефолтными значениями
            $stmt = $this->pdo->prepare("
                INSERT INTO agent_configs (server_id, interval_seconds, monitor_services, enabled)
                VALUES (:server_id, 60, '[]', TRUE)
            ");
            $stmt->execute([':server_id' => $serverId]);

            $config = [
                'interval_seconds' => 60,
                'monitor_services' => [],
                'enabled' => true
            ];
        }

        $response->getBody()->write(json_encode($config));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function updateConfig(Request $request, Response $response, $args)
    {
        $serverId = $args['id'];
        $params = $request->getParsedBody();

        // Получаем и десериализуем массив сервисов
        $monitorServices = $params['monitor_services'] ?? [];

        if (is_string($monitorServices)) {
            $monitorServices = json_decode($monitorServices, true) ?? [];
        }

        // Валидация интервала
        $interval = max(10, min(3600, (int)($params['interval_seconds'] ?? 60)));

        // Обновляем конфигурацию
        $stmt = $this->pdo->prepare("
            INSERT INTO agent_configs (server_id, interval_seconds, monitor_services, enabled)
            VALUES (:server_id, :interval, :services, TRUE)
            ON DUPLICATE KEY UPDATE
                interval_seconds = VALUES(interval_seconds),
                monitor_services = VALUES(monitor_services),
                updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            ':server_id' => $serverId,
            ':interval' => $interval,
            ':services' => json_encode($monitorServices)
        ]);

        // Обновляем статус проверки сервисов на сервере
        $enabled = $params['enabled'] ?? true;
        $stmt = $this->pdo->prepare("
            UPDATE servers SET service_check_enabled = :enabled WHERE id = :server_id
        ");
        $stmt->execute([
            ':server_id' => $serverId,
            ':enabled' => $enabled ? 1 : 0
        ]);

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getStatus(Request $request, Response $response, $args)
    {
        $serverId = $args['id'];

        // Получаем последний раз когда агент был активен
        $stmt = $this->pdo->prepare("
            SELECT s.last_metrics_at, s.last_service_check_at, ac.enabled
            FROM servers s
            LEFT JOIN agent_configs ac ON s.id = ac.server_id
            WHERE s.id = :server_id
        ");
        $stmt->execute([':server_id' => $serverId]);
        $result = $stmt->fetch();

        if (!$result) {
            $response->getBody()->write(json_encode(['error' => 'Server not found']));
            return $response->withStatus(404);
        }

        $data = [
            'status' => $result['enabled'] ? 'active' : 'disabled',
            'last_seen' => $result['last_metrics_at'],
            'last_service_check' => $result['last_service_check_at']
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function generateWindowsInstallScript(Request $request, Response $response, $args)
    {
        $queryParams = $request->getQueryParams();
        $token = $queryParams['token'] ?? null;
        $server_id = $queryParams['server_id'] ?? null;

        if (!empty($server_id) && empty($token)) {
            $stmt = $this->pdo->prepare("SELECT encrypted_token FROM agent_tokens WHERE server_id = :server_id LIMIT 1");
            $stmt->execute([':server_id' => $server_id]);
            $result = $stmt->fetch();

            if ($result && !empty($result['encrypted_token'])) {
                $token = EncryptionHelper::decrypt($result['encrypted_token']);
            }
        }

        if (empty($token)) {
            $response->getBody()->write('Token is required');
            return $response->withStatus(400);
        }

        $apiUrl = 'https://mon.mirv.top/api/v1/metrics';
        $agentPyUrl = 'https://mon.mirv.top/agent/agent.py';

        // PowerShell скрипт установки
        $lines = [];
        $lines[] = '# Скрипт установки агента мониторинга для Windows Server 2012+';
        $lines[] = '# Запустите от имени Администратора (PowerShell)';
        $lines[] = '';
        $lines[] = '$ErrorActionPreference = "Stop"';
        $lines[] = '$Token = "' . addslashes($token) . '"';
        $lines[] = '$ApiUrl = "' . addslashes($apiUrl) . '"';
        $lines[] = '$InstallDir = "C:\\Program Files\\MonAgent"';
        $lines[] = '';
        $lines[] = 'Write-Host "=== Установка агента мониторинга ===" -ForegroundColor Cyan';
        $lines[] = '';
        $lines[] = '# Проверяем права администратора';
        $lines[] = '$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)';
        $lines[] = 'if (-not $isAdmin) {';
        $lines[] = '    Write-Host "ОШИБКА: Запустите PowerShell от имени Администратора!" -ForegroundColor Red';
        $lines[] = '    exit 1';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# Включаем TLS 1.2';
        $lines[] = '[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12';
        $lines[] = '';
        $lines[] = '# Проверяем Python';
        $lines[] = 'Write-Host "Проверка Python..." -ForegroundColor Yellow';
        $lines[] = '$pythonCmd = Get-Command python -ErrorAction SilentlyContinue';
        $lines[] = 'if (-not $pythonCmd) {';
        $lines[] = '    Write-Host "Установка Python 3.12..." -ForegroundColor Yellow';
        $lines[] = '    $installer = "$env:TEMP\\python-installer.exe"';
        $lines[] = '    $pythonUrl = "https://www.python.org/ftp/python/3.12.4/python-3.12.4-amd64.exe"';
        $lines[] = '    Write-Host "Скачивание установщика Python..." -ForegroundColor Yellow';
        $lines[] = '    Invoke-WebRequest -Uri $pythonUrl -OutFile $installer -UseBasicParsing';
        $lines[] = '    Write-Host "Установка Python (тихая установка)..." -ForegroundColor Yellow';
        $lines[] = '    Start-Process -FilePath $installer -ArgumentList "/quiet", "InstallAllUsers=1", "PrependPath=1", "Include_pip=1" -Wait -NoNewWindow';
        $lines[] = '    Remove-Item $installer -Force -ErrorAction SilentlyContinue';
        $lines[] = '    $pythonCmd = Get-Command python -ErrorAction SilentlyContinue';
        $lines[] = '    if (-not $pythonCmd) {';
        $lines[] = '        Write-Host "ОШИБКА: Python не установлен. Установите вручную с https://python.org" -ForegroundColor Red';
        $lines[] = '        exit 1';
        $lines[] = '    }';
        $lines[] = '    Write-Host "Python успешно установлен!" -ForegroundColor Green';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# Устанавливаем psutil';
        $lines[] = 'Write-Host "Установка psutil..." -ForegroundColor Yellow';
        $lines[] = 'python -m pip install psutil --quiet';
        $lines[] = 'if ($LASTEXITCODE -ne 0) {';
        $lines[] = '    Write-Host "ОШИБКА: Не удалось установить psutil" -ForegroundColor Red';
        $lines[] = '    exit 1';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '# Создаём директорию';
        $lines[] = 'Write-Host "Создание директории $InstallDir..." -ForegroundColor Yellow';
        $lines[] = 'if (-not (Test-Path $InstallDir)) {';
        $lines[] = '    New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null';
        $lines[] = '}';
        $lines[] = 'Set-Location $InstallDir';
        $lines[] = '';
        $lines[] = '# Создаём конфигурационный файл';
        $lines[] = 'Write-Host "Создание конфигурации..." -ForegroundColor Yellow';
        $lines[] = '$config = @{';
        $lines[] = '    token = $Token';
        $lines[] = '    api_url = $ApiUrl';
        $lines[] = '    interval_seconds = 60';
        $lines[] = '} | ConvertTo-Json';
        $lines[] = '$config | Out-File -FilePath "$InstallDir\\config.json" -Encoding UTF8 -Force';
        $lines[] = '';
        $lines[] = '# Скачиваем agent.py';
        $lines[] = 'Write-Host "Скачивание agent.py..." -ForegroundColor Yellow';
        $lines[] = 'Invoke-WebRequest -Uri "' . addslashes($agentPyUrl) . '" -OutFile "$InstallDir\\agent.py" -UseBasicParsing';
        $lines[] = '';
        $lines[] = '# Создаём Scheduled Task для автозапуска';
        $lines[] = 'Write-Host "Регистрация службы..." -ForegroundColor Yellow';
        $lines[] = '$serviceTaskName = "MonAgent"';
        $lines[] = 'Unregister-ScheduledTask -TaskName $serviceTaskName -Confirm:$false -ErrorAction SilentlyContinue';
        $lines[] = '$trigger = New-ScheduledTaskTrigger -AtStartup';
        $lines[] = '$action = New-ScheduledTaskAction -Execute "python" -Argument "$InstallDir\\agent.py" -WorkingDirectory $InstallDir';
        $lines[] = '$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)';
        $lines[] = '$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest';
        $lines[] = 'Register-ScheduledTask -TaskName $serviceTaskName -Trigger $trigger -Action $action -Settings $settings -Principal $principal -Force | Out-Null';
        $lines[] = '';
        $lines[] = '# Запускаем задачу';
        $lines[] = 'Start-ScheduledTask -TaskName $serviceTaskName';
        $lines[] = '';
        $lines[] = 'Write-Host ""';
        $lines[] = 'Write-Host "=== Агент мониторинга установлен! ===" -ForegroundColor Green';
        $lines[] = 'Write-Host "Директория: $InstallDir" -ForegroundColor White';
        $lines[] = 'Write-Host "Служба: MonAgent (Scheduled Task)" -ForegroundColor White';
        $lines[] = 'Write-Host "Лог: $InstallDir\\agent.log" -ForegroundColor White';
        $lines[] = 'Write-Host ""';
        $lines[] = 'Write-Host "Для управления:" -ForegroundColor Cyan';
        $lines[] = 'Write-Host "  Проверить статус: Get-ScheduledTask -TaskName MonAgent" -ForegroundColor Gray';
        $lines[] = 'Write-Host "  Остановить: Disable-ScheduledTask -TaskName MonAgent" -ForegroundColor Gray';
        $lines[] = 'Write-Host "  Запустить: Start-ScheduledTask -TaskName MonAgent" -ForegroundColor Gray';
        $lines[] = 'Write-Host "  Удалить: Unregister-ScheduledTask -TaskName MonAgent -Confirm:$false" -ForegroundColor Gray';

        $script = implode("\n", $lines) . "\n";

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="install.ps1"')
            ->withBody(\Nyholm\Psr7\Utils::streamFor($script));
    }

}
