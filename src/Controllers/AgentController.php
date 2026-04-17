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
        $agentDownloadUrl = 'https://mon.mirv.top/agent/agent.py?token=' . $token;
        $installDir = '/opt/server-monitor-agent';

        $script = <<<BASH
#!/bin/bash

# =====================================================
# Скрипт установки агента мониторинга
# Сгенерировано автоматически
# =====================================================

set -e

TOKEN='{$token}'
API_URL='{$apiUrl}'
AGENT_URL='{$agentDownloadUrl}'
INSTALL_DIR='{$installDir}'

echo '=============================================='
echo ' Установка агента мониторинга серверов'
echo '=============================================='
echo ''

# Проверяем наличие Python3
if ! command -v python3 &> /dev/null; then
    echo '[1/6] Установка Python3...'
    apt-get update -qq
    apt-get install -y -qq python3 python3-pip || apt-get install -y python3 python3-pip
else
    echo '[1/6] Python3 найден'
fi

# Устанавливаем зависимости (lm-sensors и smartmontools опциональны)
echo '[2/6] Установка зависимостей (psutil, lm-sensors, smartmontools)...'
pip3 install --quiet psutil 2>/dev/null || pip3 install psutil 2>/dev/null || true
apt-get install -y -qq lm-sensors smartmontools 2>/dev/null || true

# Создаем директорию для агента
echo '[3/6] Создание директории агента...'
mkdir -p '{$installDir}'

# Скачиваем агента
echo '[4/6] Скачивание агента...'
if ! curl -fsSL '{$agentDownloadUrl}' -o '{$installDir}/agent.py' 2>/dev/null; then
    echo 'ERROR: Не удалось скачать агента. Проверьте токен и подключение к серверу.'
    exit 1
fi

if ! grep -q 'psutil' '{$installDir}/agent.py'; then
    echo 'ERROR: Скачанный файл не является агентом мониторинга.'
    exit 1
fi

chmod +x '{$installDir}/agent.py'

# Создаем конфигурационный файл
echo '[5/6] Создание конфигурации...'
cat > '{$installDir}/config.json' <<'CONFIG_EOF'
{
    "token": "{$token}",
    "api_url": "{$apiUrl}",
    "interval_seconds": 60
}
CONFIG_EOF

# Создаем systemd сервис
echo '[6/6] Регистрация системной службы...'
cat > /etc/systemd/system/server-monitor-agent.service <<'SERVICE_EOF'
[Unit]
Description=Server Monitor Agent
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory={$installDir}
ExecStart=/usr/bin/python3 {$installDir}/agent.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
SERVICE_EOF

# Активируем и запускаем сервис
systemctl daemon-reload
systemctl enable server-monitor-agent
systemctl stop server-monitor-agent 2>/dev/null || true
systemctl start server-monitor-agent

echo ''
echo '=============================================='
echo ' Агент мониторинга успешно установлен!'
echo '=============================================='
echo ''
echo "Директория: {$installDir}"
echo 'Логи:       journalctl -u server-monitor-agent -f'
echo 'Статус:     systemctl status server-monitor-agent'
echo ''
BASH;

        $response->getBody()->write($script);
        return $response
            ->withHeader('Content-Type', 'application/x-shellscript')
            ->withHeader('Content-Disposition', 'attachment; filename="install.sh"');
    }

    public function downloadAgent(Request $request, Response $response, $args)
    {
        $queryParams = $request->getQueryParams();
        $token = $queryParams['token'] ?? null;

        if (empty($token)) {
            $response->getBody()->write('Token is required');
            return $response->withStatus(403);
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $this->pdo->prepare("SELECT server_id FROM agent_tokens WHERE token_hash = :hash LIMIT 1");
        $stmt->execute([':hash' => $tokenHash]);
        $result = $stmt->fetch();

        if (!$result) {
            $response->getBody()->write('Invalid token');
            return $response->withStatus(403);
        }

        $stmt = $this->pdo->prepare("UPDATE agent_tokens SET last_used_at = NOW() WHERE token_hash = :hash");
        $stmt->execute([':hash' => $tokenHash]);

        $agentPath = dirname(__DIR__, 2) . '/agent.py';
        if (!file_exists($agentPath)) {
            $response->getBody()->write('Agent not found');
            return $response->withStatus(404);
        }

        $content = file_get_contents($agentPath);

        return $response
            ->getBody()
            ->write($content)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="agent.py"');
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

        $response->getBody()->write($script);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="install.ps1"');
    }



    public function generateWindowsBatScript(Request $request, Response $response, $args)
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

        // PowerShell скрипт (будет вставлен в BAT)
        $psLines = [];
        $psLines[] = '$ErrorActionPreference = "Stop"';
        $psLines[] = '$Token = "' . addslashes($token) . '"';
        $psLines[] = '$ApiUrl = "' . addslashes($apiUrl) . '"';
        $psLines[] = '$InstallDir = "C:\\Program Files\\MonAgent"';
        $psLines[] = 'Write-Host "=== Установка агента мониторинга ===" -ForegroundColor Cyan';
        $psLines[] = '$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)';
        $psLines[] = 'if (-not $isAdmin) { Write-Host "ОШИБКА: Запустите от имени Администратора!" -ForegroundColor Red; exit 1 }';
        $psLines[] = '[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12';
        $psLines[] = 'Write-Host "Проверка Python..." -ForegroundColor Yellow';
        $psLines[] = '$pythonCmd = Get-Command python -ErrorAction SilentlyContinue';
        $psLines[] = 'if (-not $pythonCmd) {';
        $psLines[] = '    Write-Host "Установка Python 3.12..." -ForegroundColor Yellow';
        $psLines[] = '    $inst = "$env:TEMP\\python-inst.exe"';
        $psLines[] = '    Invoke-WebRequest -Uri "https://www.python.org/ftp/python/3.12.4/python-3.12.4-amd64.exe" -OutFile $inst -UseBasicParsing';
        $psLines[] = '    Start-Process -FilePath $inst -ArgumentList "/quiet","InstallAllUsers=1","PrependPath=1","Include_pip=1" -Wait -NoNewWindow';
        $psLines[] = '    Remove-Item $inst -Force -ErrorAction SilentlyContinue';
        $psLines[] = '    $pythonCmd = Get-Command python -ErrorAction SilentlyContinue';
        $psLines[] = '    if (-not $pythonCmd) { Write-Host "ОШИБКА: Python не установлен" -ForegroundColor Red; exit 1 }';
        $psLines[] = '    Write-Host "Python установлен!" -ForegroundColor Green';
        $psLines[] = '}';
        $psLines[] = 'Write-Host "Установка psutil..." -ForegroundColor Yellow';
        $psLines[] = 'python -m pip install psutil --quiet';
        $psLines[] = 'Write-Host "Создание $InstallDir..." -ForegroundColor Yellow';
        $psLines[] = 'if (-not (Test-Path $InstallDir)) { New-Item -ItemType Directory -Path $InstallDir -Force | Out-Null }';
        $psLines[] = 'Set-Location $InstallDir';
        $psLines[] = '$cfg = @{ token = $Token; api_url = $ApiUrl; interval_seconds = 60 } | ConvertTo-Json';
        $psLines[] = '$cfg | Out-File -FilePath "$InstallDir\\config.json" -Encoding UTF8 -Force';
        $psLines[] = 'Write-Host "Скачивание agent.py..." -ForegroundColor Yellow';
        $psLines[] = 'Invoke-WebRequest -Uri "' . addslashes($agentPyUrl) . '" -OutFile "$InstallDir\\agent.py" -UseBasicParsing';
        $psLines[] = 'Write-Host "Регистрация службы..." -ForegroundColor Yellow';
        $psLines[] = 'Unregister-ScheduledTask -TaskName "MonAgent" -Confirm:$false -ErrorAction SilentlyContinue';
        $psLines[] = '$t = New-ScheduledTaskTrigger -AtStartup';
        $psLines[] = '$a = New-ScheduledTaskAction -Execute "python" -Argument "$InstallDir\\agent.py" -WorkingDirectory $InstallDir';
        $psLines[] = '$s = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)';
        $psLines[] = '$p = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest';
        $psLines[] = 'Register-ScheduledTask -TaskName "MonAgent" -Trigger $t -Action $a -Settings $s -Principal $p -Force | Out-Null';
        $psLines[] = 'Start-ScheduledTask -TaskName "MonAgent"';
        $psLines[] = 'Write-Host ""';
        $psLines[] = 'Write-Host "=== Агент установлен! ===" -ForegroundColor Green';
        $psLines[] = 'Write-Host "Директория: $InstallDir" -ForegroundColor White';
        $psLines[] = 'Write-Host "Служба: MonAgent (Scheduled Task)" -ForegroundColor White';
        $psLines[] = 'Write-Host ""';
        $psLines[] = 'Write-Host "Управление:" -ForegroundColor Cyan';
        $psLines[] = 'Write-Host "  Статус: Get-ScheduledTask -TaskName MonAgent" -ForegroundColor Gray';
        $psLines[] = 'Write-Host "  Стоп: Disable-ScheduledTask -TaskName MonAgent" -ForegroundColor Gray';
        $psLines[] = 'Write-Host "  Старт: Start-ScheduledTask -TaskName MonAgent" -ForegroundColor Gray';

        $psContent = implode("`n", $psLines);

        // BAT обёртка
        $bat = <<<BAT
@echo off
chcp 65001 >nul 2>&1
echo.
echo ===============================
echo  Установка агента мониторинга
echo ===============================
echo.

:: Проверяем права администратора
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo ОШИБКА: Запустите этот файл от имени Администратора!
    echo (Правый клик -> Запуск от имени администратора)
    pause
    exit /b 1
)

:: Создаём временный PS1 файл
set "TEMP_PS1=%TEMP%\\monagent_setup_%RANDOM%.ps1"

echo Скрипт установки... > "%TEMP_PS1%"
echo %psContent% >> "%TEMP_PS1%"

:: Запускаем PowerShell с обходом политики
powershell.exe -ExecutionPolicy Bypass -File "%TEMP_PS1%"

:: Удаляем временный файл
del "%TEMP_PS1%" 2>nul

echo.
pause
BAT;

        $response->getBody()->write($bat);
        return $response
            ->withHeader('Content-Type', 'application/bat')
            ->withHeader('Content-Disposition', 'attachment; filename="install.bat"');
    }
}
