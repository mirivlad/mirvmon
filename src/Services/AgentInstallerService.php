<?php

declare(strict_types=1);

namespace App\Services;

use JsonException;
use RuntimeException;

final class AgentInstallerService
{
    public function linux(string $baseUrl, string $agentToken): string
    {
        $this->assertInputs($baseUrl, $agentToken);

        return str_replace(
            ['__BASE_URL__', '__CONFIG__'],
            [
                $baseUrl,
                $this->configJson(
                    $baseUrl,
                    $agentToken,
                    '/var/lib/mirvmon-agent/queue.json'
                ),
            ],
            <<<'SH'
#!/bin/sh
set -eu
umask 077

INSTALL_DIR='/opt/mirvmon-agent'
CONFIG_DIR='/etc/mirvmon-agent'
STATE_DIR='/var/lib/mirvmon-agent'
CONFIG_PATH="$CONFIG_DIR/config.json"
QUEUE_PATH="$STATE_DIR/queue.json"
AGENT_USER='mirvmon-agent'
SERVICE_UNIT='/etc/systemd/system/mirvmon-agent.service'
ARTIFACT_URL='__BASE_URL__/agent/binaries/linux-amd64'

fail() {
    echo "MirvMon agent installation failed: $1" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail 'Run this installer as root.'
command -v systemctl >/dev/null 2>&1 || fail 'A systemd host is required.'

if ! id "$AGENT_USER" >/dev/null 2>&1; then
    if command -v useradd >/dev/null 2>&1; then
        useradd --system --home-dir "$STATE_DIR" --shell /usr/sbin/nologin "$AGENT_USER"
    elif command -v adduser >/dev/null 2>&1; then
        adduser --system --home "$STATE_DIR" --disabled-login --shell /usr/sbin/nologin "$AGENT_USER"
    else
        fail 'Cannot create the mirvmon-agent service account.'
    fi
fi

install -d -m 0755 -o root -g root "$INSTALL_DIR"
install -d -m 0750 -o root -g "$AGENT_USER" "$CONFIG_DIR"
install -d -m 0700 -o "$AGENT_USER" -g "$AGENT_USER" "$STATE_DIR"
STAGING_DIR="$INSTALL_DIR/.staging-$$"
mkdir "$STAGING_DIR"
trap 'rm -rf "$STAGING_DIR"' EXIT HUP INT TERM

if command -v curl >/dev/null 2>&1; then
    curl --fail --silent --show-error --location "$ARTIFACT_URL" -o "$STAGING_DIR/mirvmon-agent"
elif command -v wget >/dev/null 2>&1; then
    wget -qO "$STAGING_DIR/mirvmon-agent" "$ARTIFACT_URL"
else
    fail 'curl or wget is required to download the native agent.'
fi
chmod 0755 "$STAGING_DIR/mirvmon-agent"

SERVER_CONFIG="$STAGING_DIR/server-config.json"
cat > "$SERVER_CONFIG" <<'MIRVMON_CONFIG'
__CONFIG__
MIRVMON_CONFIG
chmod 0600 "$SERVER_CONFIG"
"$STAGING_DIR/mirvmon-agent" check --config "$SERVER_CONFIG"

# Migration produces new files first; old config and queue stay intact on error.
"$STAGING_DIR/mirvmon-agent" migrate --source-config "$CONFIG_PATH" --source-queue "$QUEUE_PATH" --server-config "$SERVER_CONFIG" --output-config "$STAGING_DIR/config.json" --output-queue "$STAGING_DIR/queue.json"

systemctl stop mirvmon-agent.service >/dev/null 2>&1 || true
timestamp=$(date -u +%Y%m%d%H%M%S)
[ ! -f "$CONFIG_PATH" ] || cp -p "$CONFIG_PATH" "$CONFIG_PATH.legacy-$timestamp"
[ ! -f "$QUEUE_PATH" ] || cp -p "$QUEUE_PATH" "$QUEUE_PATH.legacy-$timestamp"
install -m 0755 -o root -g root "$STAGING_DIR/mirvmon-agent" "$INSTALL_DIR/mirvmon-agent"
install -m 0640 -o root -g "$AGENT_USER" "$STAGING_DIR/config.json" "$CONFIG_PATH"
install -m 0600 -o "$AGENT_USER" -g "$AGENT_USER" "$STAGING_DIR/queue.json" "$QUEUE_PATH"

cat > "$SERVICE_UNIT" <<'MIRVMON_SERVICE'
[Unit]
Description=MirvMon monitoring agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=mirvmon-agent
Group=mirvmon-agent
WorkingDirectory=/var/lib/mirvmon-agent
ExecStart=/opt/mirvmon-agent/mirvmon-agent run --config /etc/mirvmon-agent/config.json
Restart=on-failure
RestartSec=10
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
MIRVMON_SERVICE
systemctl daemon-reload
systemctl enable mirvmon-agent.service
systemctl restart mirvmon-agent.service
systemctl is-active --quiet mirvmon-agent.service || fail 'systemd could not start mirvmon-agent.service.'
echo 'MirvMon agent installed. Check: systemctl status mirvmon-agent'
SH
        );
    }

    public function windowsPowerShell(string $baseUrl, string $agentToken): string
    {
        return $this->windowsScript($baseUrl, $agentToken, 'windows-amd64');
    }

    public function windowsBatch(string $baseUrl, string $agentToken): string
    {
        return $this->batchWrapper($this->windowsPowerShell($baseUrl, $agentToken));
    }

    public function windowsLegacyPowerShell(string $baseUrl, string $agentToken): string
    {
        return $this->windowsScript($baseUrl, $agentToken, 'windows-legacy-amd64');
    }

    public function windowsLegacyBatch(string $baseUrl, string $agentToken): string
    {
        return $this->batchWrapper($this->windowsLegacyPowerShell($baseUrl, $agentToken));
    }

    private function windowsScript(string $baseUrl, string $agentToken, string $artifact): string
    {
        $this->assertInputs($baseUrl, $agentToken);

        return str_replace(
            ['__ARTIFACT_URL__', '__CONFIG__'],
            [
                $baseUrl . '/agent/binaries/' . $artifact,
                $this->configJson(
                    $baseUrl,
                    $agentToken,
                    '%PROGRAMDATA%\\MirvMon\\Agent\\queue.json'
                ),
            ],
            <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$InstallDir = Join-Path $env:ProgramFiles 'MirvMon\Agent'
$StateDir = Join-Path $env:ProgramData 'MirvMon\Agent'
$ConfigPath = Join-Path $StateDir 'config.json'
$QueuePath = Join-Path $StateDir 'queue.json'
$ArtifactUrl = '__ARTIFACT_URL__'
$StageDir = Join-Path $InstallDir ('.staging-' + [guid]::NewGuid().ToString())

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this installer from an elevated PowerShell session.'
}
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]3072
} catch {
}

New-Item -ItemType Directory -Force -Path $InstallDir, $StateDir, $StageDir | Out-Null
try {
    $webClient = New-Object Net.WebClient
    $webClient.DownloadFile($ArtifactUrl, (Join-Path $StageDir 'mirvmon-agent.exe'))
    $webClient.Dispose()
@'
__CONFIG__
'@ | Set-Content -LiteralPath (Join-Path $StageDir 'server-config.json') -Encoding UTF8

    & (Join-Path $StageDir 'mirvmon-agent.exe') check --config (Join-Path $StageDir 'server-config.json')
    if ($LASTEXITCODE -ne 0) { throw 'The downloaded native agent failed its configuration check.' }
    & (Join-Path $StageDir 'mirvmon-agent.exe') migrate --source-config $ConfigPath --source-queue $QueuePath --server-config (Join-Path $StageDir 'server-config.json') --output-config (Join-Path $StageDir 'config.json') --output-queue (Join-Path $StageDir 'queue.json')
    if ($LASTEXITCODE -ne 0) { throw 'Native state migration failed; previous agent files were left unchanged.' }

    sc.exe stop MirvMonAgent | Out-Null
    schtasks.exe /End /TN 'MirvMon Agent' 2>$null | Out-Null
    $timestamp = Get-Date -Format 'yyyyMMddHHmmss'
    if (Test-Path $ConfigPath) { Copy-Item -LiteralPath $ConfigPath -Destination ($ConfigPath + '.legacy-' + $timestamp) -Force }
    if (Test-Path $QueuePath) { Copy-Item -LiteralPath $QueuePath -Destination ($QueuePath + '.legacy-' + $timestamp) -Force }
    Move-Item -LiteralPath (Join-Path $StageDir 'mirvmon-agent.exe') -Destination (Join-Path $InstallDir 'mirvmon-agent.exe') -Force
    Move-Item -LiteralPath (Join-Path $StageDir 'config.json') -Destination $ConfigPath -Force
    Move-Item -LiteralPath (Join-Path $StageDir 'queue.json') -Destination $QueuePath -Force

    schtasks.exe /Delete /TN 'MirvMon Agent' /F 2>$null | Out-Null
    sc.exe delete MirvMonAgent | Out-Null
    $serviceCommand = '"' + (Join-Path $InstallDir 'mirvmon-agent.exe') + '" run --config "' + $ConfigPath + '"'
    sc.exe create MirvMonAgent binPath= $serviceCommand start= auto obj= LocalSystem | Out-Null
    sc.exe description MirvMonAgent 'MirvMon outbound monitoring agent' | Out-Null
    icacls $StateDir /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' | Out-Null
    icacls (Join-Path $InstallDir 'mirvmon-agent.exe') /inheritance:r /grant:r 'SYSTEM:RX' 'Administrators:F' | Out-Null
    sc.exe start MirvMonAgent | Out-Null
    Write-Host 'MirvMon agent installed. Check: sc.exe query MirvMonAgent'
} finally {
    if (Test-Path $StageDir) { Remove-Item -LiteralPath $StageDir -Recurse -Force -ErrorAction SilentlyContinue }
}
POWERSHELL
        );
    }

    private function batchWrapper(string $powerShell): string
    {
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

    private function assertInputs(string $baseUrl, string $agentToken): void
    {
        new PublicUrlResolver($baseUrl);
        if (preg_match('/^[a-f0-9]{64}$/', $agentToken) !== 1) {
            throw new RuntimeException('Invalid agent token.');
        }
    }

    private function configJson(string $baseUrl, string $agentToken, string $queuePath): string
    {
        try {
            return json_encode([
                'api_url' => $baseUrl . '/api/v1/metrics',
                'config_url' => $baseUrl . '/api/v1/agent/config',
                'token' => $agentToken,
                'interval_seconds' => 60,
                'verify_tls' => true,
                'queue_path' => $queuePath,
                'collect_process_commands' => false,
                'queue_limit' => 1000,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot generate agent configuration.', 0, $exception);
        }
    }
}
