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
        return $this->batchWrapper(
            $this->windowsPowerShell($baseUrl, $agentToken)
        );
    }

    /**
     * Windows 7 and Server 2008 cannot run the Python agent: CPython 3.9
     * dropped those releases, and PowerShell 2.0 has neither
     * Invoke-WebRequest, ConvertTo-Json nor Register-ScheduledTask. This
     * installer writes a self-contained collector that speaks the same
     * envelope v2 over WMI, HttpWebRequest and schtasks.
     */
    public function windowsLegacyPowerShell(
        string $baseUrl,
        string $agentToken
    ): string {
        $this->assertInputs($baseUrl, $agentToken);
        // A single-quoted here-string keeps every character literal, so the
        // collector only has to avoid a line that starts with the terminator.
        $collector = str_replace(
            ['{{API_URL}}', '{{TOKEN}}'],
            [$baseUrl . '/api/v1/metrics', $agentToken],
            $this->legacyCollector()
        );
        if (preg_match("/^'@/m", $collector) === 1) {
            throw new RuntimeException('Legacy collector breaks the here-string.');
        }

        return <<<POWERSHELL
\$ErrorActionPreference = 'Stop'
\$InstallDir = Join-Path \$env:ProgramFiles 'MirvMon\\Agent'
\$StateDir = Join-Path \$env:ProgramData 'MirvMon\\Agent'

\$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
\$principal = New-Object Security.Principal.WindowsPrincipal(\$identity)
if (-not \$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this installer from an elevated PowerShell session.'
}

if (-not (Test-Path \$InstallDir)) { New-Item -ItemType Directory -Path \$InstallDir | Out-Null }
if (-not (Test-Path \$StateDir)) { New-Item -ItemType Directory -Path \$StateDir | Out-Null }

\$Collector = @'
{$collector}
'@
Set-Content -LiteralPath (Join-Path \$InstallDir 'collector.ps1') -Value \$Collector -Encoding UTF8

icacls \$StateDir /inheritance:r /grant:r 'SYSTEM:(OI)(CI)F' 'Administrators:(OI)(CI)F' | Out-Null
icacls (Join-Path \$InstallDir 'collector.ps1') /inheritance:r /grant:r 'SYSTEM:(RX)' 'Administrators:F' | Out-Null

\$Command = 'powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' `
    + (Join-Path \$InstallDir 'collector.ps1') + '"'
schtasks.exe /Create /TN 'MirvMon Agent' /TR \$Command /SC MINUTE /MO 1 /RU SYSTEM /RL HIGHEST /F | Out-Null
schtasks.exe /Run /TN 'MirvMon Agent' | Out-Null
Write-Host 'MirvMon legacy agent installed. Samples are sent once per minute.'
POWERSHELL;
    }

    public function windowsLegacyBatch(string $baseUrl, string $agentToken): string
    {
        return $this->batchWrapper(
            $this->windowsLegacyPowerShell($baseUrl, $agentToken)
        );
    }

    /** @return list<string> */
    public static function agentFiles(): array
    {
        return self::AGENT_FILES;
    }

    /** The agent itself, deliberately limited to PowerShell 2.0 syntax. */
    private function legacyCollector(): string
    {
        return <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$ApiUrl = '{{API_URL}}'
$Token = '{{TOKEN}}'
$QueuePath = Join-Path $env:ProgramData 'MirvMon\Agent\queue.txt'
$QueueLimit = 200

function Format-JsonText($value) {
    $text = [string]$value
    $text = $text.Replace('\', '\\').Replace('"', '\"')
    $text = $text.Replace("`r", '\r').Replace("`n", '\n').Replace("`t", '\t')
    return '"' + $text + '"'
}

function Format-JsonNumber($value) {
    return [string][math]::Round([double]$value, 2) -replace ',', '.'
}

function Get-MetricName($prefix, $raw) {
    $name = ([string]$raw).ToLower() -replace '[^a-z0-9_]', '_'
    $name = $name.Trim('_')
    if ($name -eq '') { $name = 'unknown' }
    $full = $prefix + $name
    if ($full.Length -gt 100) { $full = $full.Substring(0, 100) }
    return $full
}

function Get-Metrics {
    $metrics = @{}

    $os = Get-WmiObject Win32_OperatingSystem
    $total = [double]$os.TotalVisibleMemorySize
    $free = [double]$os.FreePhysicalMemory
    if ($total -gt 0) {
        $metrics['ram_used'] = (($total - $free) / $total) * 100
        $metrics['ram_total_gb'] = $total / 1048576
    }
    try {
        $boot = [Management.ManagementDateTimeConverter]::ToDateTime($os.LastBootUpTime)
        $metrics['uptime'] = ((Get-Date) - $boot).TotalSeconds
    } catch {
    }

    $cpu = $null
    try {
        $cpu = Get-WmiObject Win32_PerfFormattedData_PerfOS_Processor |
            Where-Object { $_.Name -eq '_Total' }
    } catch {
    }
    if ($cpu -ne $null) {
        $metrics['cpu_load'] = [double]$cpu.PercentProcessorTime
    } else {
        $load = Get-WmiObject Win32_Processor | Measure-Object -Property LoadPercentage -Average
        $metrics['cpu_load'] = [double]$load.Average
    }

    $systemDrive = ([string]$env:SystemDrive).TrimEnd(':').ToLower()
    foreach ($disk in Get-WmiObject Win32_LogicalDisk -Filter 'DriveType = 3') {
        $size = [double]$disk.Size
        if ($size -le 0) { continue }
        $letter = ([string]$disk.DeviceID).TrimEnd(':').ToLower()
        $used = (($size - [double]$disk.FreeSpace) / $size) * 100
        $metrics[(Get-MetricName 'disk_used_' $letter)] = $used
        $metrics[(Get-MetricName 'disk_total_gb_' $letter)] = $size / 1073741824
        if ($letter -eq $systemDrive) { $metrics['disk_used'] = $used }
    }

    try {
        $interfaces = Get-WmiObject Win32_PerfFormattedData_Tcpip_NetworkInterface
        foreach ($interface in $interfaces) {
            $name = [string]$interface.Name
            if ($name -match 'Loopback|isatap|Teredo') { continue }
            $metrics[(Get-MetricName 'net_in_' $name)] = [double]$interface.BytesReceivedPersec
            $metrics[(Get-MetricName 'net_out_' $name)] = [double]$interface.BytesSentPersec
        }
    } catch {
    }

    return $metrics
}

function Get-Services {
    $services = @()
    foreach ($service in Get-Service) {
        $name = [string]$service.Name
        if ($name -notmatch '^[A-Za-z0-9_.@:-]{1,255}$') { continue }
        if ($service.Status -eq 'Running') {
            $status = 'running'
        } elseif ($service.Status -eq 'Stopped') {
            $status = 'stopped'
        } else {
            $status = 'unknown'
        }
        $services += ,@($name, $status, [string]$service.Status)
        if ($services.Count -ge 500) { break }
    }
    return $services
}

function Build-Envelope {
    $metrics = Get-Metrics
    $parts = @()
    foreach ($key in $metrics.Keys) {
        $parts += (Format-JsonText $key) + ':' + (Format-JsonNumber $metrics[$key])
    }
    $metricsJson = '{' + [string]::Join(',', $parts) + '}'

    $serviceParts = @()
    foreach ($service in Get-Services) {
        $serviceParts += '{' `
            + '"name":' + (Format-JsonText $service[0]) + ',' `
            + '"status":' + (Format-JsonText $service[1]) + ',' `
            + '"load_state":"loaded",' `
            + '"active_state":' + (Format-JsonText $service[1]) + ',' `
            + '"sub_state":' + (Format-JsonText $service[2]) `
            + '}'
    }
    $servicesJson = '[' + [string]::Join(',', $serviceParts) + ']'

    $sampleTime = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ss') + 'Z'
    return '{' `
        + '"version":2,' `
        + '"sample_id":' + (Format-JsonText ([guid]::NewGuid().ToString())) + ',' `
        + '"sample_time":' + (Format-JsonText $sampleTime) + ',' `
        + '"token":' + (Format-JsonText $Token) + ',' `
        + '"metrics":' + $metricsJson + ',' `
        + '"services":' + $servicesJson `
        + '}'
}

function Send-Envelope($envelope) {
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]3072
    } catch {
    }

    $request = [Net.HttpWebRequest]::Create($ApiUrl)
    $request.Method = 'POST'
    $request.ContentType = 'application/json'
    $request.Accept = 'application/json'
    $request.Timeout = 15000
    $request.ReadWriteTimeout = 15000
    $bytes = [Text.Encoding]::UTF8.GetBytes($envelope)
    $request.ContentLength = $bytes.Length
    try {
        $stream = $request.GetRequestStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
        $response = $request.GetResponse()
        $code = [int]$response.StatusCode
        $response.Close()
        return $code
    } catch [Net.WebException] {
        if ($_.Exception.Response -ne $null) {
            return [int]$_.Exception.Response.StatusCode
        }
        return 0
    } catch {
        return 0
    }
}

# A rejected sample is malformed or expired: retrying it forever is pointless.
function Test-Discardable($code) {
    return ($code -eq 400 -or $code -eq 413 -or $code -eq 422)
}

$pending = @()
if (Test-Path $QueuePath) {
    $pending = @(Get-Content -LiteralPath $QueuePath | Where-Object { $_.Trim() -ne '' })
    Remove-Item -LiteralPath $QueuePath -Force
}
$pending += (Build-Envelope)

$failed = @()
foreach ($envelope in $pending) {
    $code = Send-Envelope $envelope
    if ($code -eq 200 -or $code -eq 202 -or (Test-Discardable $code)) { continue }
    $failed += $envelope
}

if ($failed.Count -gt 0) {
    if ($failed.Count -gt $QueueLimit) {
        $failed = $failed[($failed.Count - $QueueLimit)..($failed.Count - 1)]
    }
    Set-Content -LiteralPath $QueuePath -Value $failed -Encoding UTF8
}
POWERSHELL;
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
