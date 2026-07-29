<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

final class AgentInstallerService
{
    private const REQUESTS_VERSION = '2.34.2';
    private const PSUTIL_VERSION = '7.2.2';
    private const AGENT_FILES = [
        '__init__.py',
        'config.py',
        'client.py',
        'queue.py',
        'collectors.py',
        'redaction.py',
    ];

    public function linux(string $baseUrl, string $agentToken): string
    {
        $this->assertInputs($baseUrl, $agentToken);
        $config = $this->configJson($baseUrl, $agentToken, '/var/lib/mirvmon-agent');
        $files = implode(' ', self::AGENT_FILES);
        $requestsVersion = self::REQUESTS_VERSION;
        $psutilVersion = self::PSUTIL_VERSION;

        return <<<BASH
#!/bin/sh
set -eu

if [ "\$(id -u)" -ne 0 ]; then
    echo "Run this installer as root." >&2
    exit 1
fi

BASE_URL='{$baseUrl}'
INSTALL_DIR='/opt/mirvmon-agent'
CONFIG_DIR='/etc/mirvmon-agent'
STATE_DIR='/var/lib/mirvmon-agent'
AGENT_USER='mirvmon-agent'

if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y --no-install-recommends ca-certificates curl python3 python3-venv
elif command -v dnf >/dev/null 2>&1; then
    dnf install -y ca-certificates curl python3
elif command -v apk >/dev/null 2>&1; then
    apk add --no-cache ca-certificates curl python3 py3-pip
else
    echo "Install Python 3.11+, curl, and CA certificates first." >&2
    exit 1
fi

if ! id "\$AGENT_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "\$STATE_DIR" --create-home --shell /usr/sbin/nologin "\$AGENT_USER"
fi

install -d -m 0755 -o root -g root "\$INSTALL_DIR/mirvmon_agent"
install -d -m 0750 -o root -g "\$AGENT_USER" "\$CONFIG_DIR"
install -d -m 0700 -o "\$AGENT_USER" -g "\$AGENT_USER" "\$STATE_DIR"

curl --fail --silent --show-error --location \
    "\$BASE_URL/get-agent" -o "\$INSTALL_DIR/agent.py"
for file in {$files}; do
    curl --fail --silent --show-error --location \
        "\$BASE_URL/agent/files/\$file" \
        -o "\$INSTALL_DIR/mirvmon_agent/\$file"
done

python3 -m venv "\$INSTALL_DIR/venv"
"\$INSTALL_DIR/venv/bin/python" -m pip install --disable-pip-version-check \
    requests=={$requestsVersion} psutil=={$psutilVersion}

cat > "\$CONFIG_DIR/config.json" <<'MIRVMON_CONFIG'
{$config}
MIRVMON_CONFIG
chown root:"\$AGENT_USER" "\$CONFIG_DIR/config.json"
chmod 0640 "\$CONFIG_DIR/config.json"
chmod 0755 "\$INSTALL_DIR/agent.py"

cat > /etc/default/mirvmon-agent <<'MIRVMON_ENV'
# Optional outbound proxy:
# HTTPS_PROXY=http://proxy.example:3128
# HTTP_PROXY=http://proxy.example:3128
# NO_PROXY=localhost,127.0.0.1
MIRVMON_ENV
chmod 0640 /etc/default/mirvmon-agent

cat > /etc/systemd/system/mirvmon-agent.service <<'MIRVMON_SERVICE'
[Unit]
Description=MirvMon monitoring agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=mirvmon-agent
Group=mirvmon-agent
EnvironmentFile=-/etc/default/mirvmon-agent
WorkingDirectory=/var/lib/mirvmon-agent
ExecStart=/opt/mirvmon-agent/venv/bin/python /opt/mirvmon-agent/agent.py --config /etc/mirvmon-agent/config.json
Restart=on-failure
RestartSec=10
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/mirvmon-agent

[Install]
WantedBy=multi-user.target
MIRVMON_SERVICE

systemctl daemon-reload
systemctl enable --now mirvmon-agent.service
echo "MirvMon agent installed. Check: systemctl status mirvmon-agent"
BASH;
    }

    public function windowsPowerShell(
        string $baseUrl,
        string $agentToken
    ): string {
        $this->assertInputs($baseUrl, $agentToken);
        $config = $this->configJson(
            $baseUrl,
            $agentToken,
            '%PROGRAMDATA%\\MirvMon\\Agent'
        );
        $downloadLines = [];
        foreach (self::AGENT_FILES as $file) {
            $downloadLines[] = sprintf(
                "Invoke-WebRequest -Uri \"\$BaseUrl/agent/files/%s\" "
                . "-OutFile \"\$PackageDir\\%s\" -UseBasicParsing",
                $file,
                $file
            );
        }
        $downloads = implode("\r\n", $downloadLines);
        $requestsVersion = self::REQUESTS_VERSION;
        $psutilVersion = self::PSUTIL_VERSION;

        return <<<POWERSHELL
\$ErrorActionPreference = 'Stop'
\$BaseUrl = '{$baseUrl}'
\$InstallDir = Join-Path \$env:ProgramFiles 'MirvMon\\Agent'
\$StateDir = Join-Path \$env:ProgramData 'MirvMon\\Agent'
\$PackageDir = Join-Path \$InstallDir 'mirvmon_agent'

\$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
\$principal = New-Object Security.Principal.WindowsPrincipal(\$identity)
if (-not \$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this installer from an elevated PowerShell session.'
}

\$Python = Get-Command python -ErrorAction SilentlyContinue
if (-not \$Python) {
    throw 'Python 3.11 or newer is required. Install it from python.org and rerun.'
}

New-Item -ItemType Directory -Force -Path \$InstallDir, \$StateDir, \$PackageDir | Out-Null
Invoke-WebRequest -Uri "\$BaseUrl/get-agent" -OutFile "\$InstallDir\\agent.py" -UseBasicParsing
{$downloads}

& \$Python.Source -m venv "\$InstallDir\\venv"
& "\$InstallDir\\venv\\Scripts\\python.exe" -m pip install --disable-pip-version-check requests=={$requestsVersion} psutil=={$psutilVersion}

@'
{$config}
'@ | Set-Content -LiteralPath "\$StateDir\\config.json" -Encoding UTF8

icacls \$StateDir /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' | Out-Null
\$Action = New-ScheduledTaskAction `
    -Execute "\$InstallDir\\venv\\Scripts\\python.exe" `
    -Argument "`"\$InstallDir\\agent.py`" --config `"\$StateDir\\config.json`""
\$Trigger = New-ScheduledTaskTrigger -AtStartup
\$Settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1)
\$Principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName 'MirvMon Agent' -Action \$Action -Trigger \$Trigger -Settings \$Settings -Principal \$Principal -Force | Out-Null
Start-ScheduledTask -TaskName 'MirvMon Agent'
Write-Host 'MirvMon agent installed.'
POWERSHELL;
    }

    public function windowsBatch(string $baseUrl, string $agentToken): string
    {
        $powerShell = $this->windowsPowerShell($baseUrl, $agentToken);
        $utf16 = iconv('UTF-8', 'UTF-16LE', $powerShell);
        if ($utf16 === false) {
            throw new RuntimeException('Cannot encode Windows installer.');
        }
        $encoded = base64_encode($utf16);

        return <<<BAT
@echo off
setlocal
net session >nul 2>&1
if errorlevel 1 (
  echo Run this installer as Administrator.
  exit /b 1
)
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand {$encoded}
exit /b %errorlevel%
BAT;
    }

    /** @return list<string> */
    public static function agentFiles(): array
    {
        return self::AGENT_FILES;
    }

    private function assertInputs(string $baseUrl, string $agentToken): void
    {
        new PublicUrlResolver($baseUrl);
        if (preg_match('/^[a-f0-9]{64}$/', $agentToken) !== 1) {
            throw new RuntimeException('Invalid agent token.');
        }
    }

    private function configJson(
        string $baseUrl,
        string $agentToken,
        string $stateDirectory
    ): string {
        $separator = str_starts_with($stateDirectory, '%') ? '\\' : '/';

        try {
            return json_encode([
                'api_url' => $baseUrl . '/api/v1/metrics',
                'config_url' => $baseUrl . '/api/v1/agent/config',
                'token' => $agentToken,
                'interval_seconds' => 60,
                'verify_tls' => true,
                'queue_path' => $stateDirectory . $separator . 'queue.json',
                'collect_process_commands' => false,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot generate agent configuration.', 0, $exception);
        }
    }
}
