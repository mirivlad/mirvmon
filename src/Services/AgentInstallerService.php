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

        return $this->linuxScript($baseUrl, $config);
    }

    private function linuxScript(string $baseUrl, string $config): string
    {
        $template = <<<'SH'
#!/bin/sh
set -eu
umask 077

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer as root." >&2
    exit 1
fi

BASE_URL='__BASE_URL__'
INSTALL_DIR='/opt/mirvmon-agent'
CONFIG_DIR='/etc/mirvmon-agent'
STATE_DIR='/var/lib/mirvmon-agent'
AGENT_USER='mirvmon-agent'
RELEASES_DIR="$INSTALL_DIR/releases"
CURRENT_LINK="$INSTALL_DIR/current"
SYSTEMD_UNIT='/etc/systemd/system/mirvmon-agent.service'
SYSV_SCRIPT='/etc/init.d/mirvmon-agent'
SCL_NAME=''
PYTHON=''

fail() {
    echo "MirvMon agent installation failed: $1" >&2
    exit 1
}

python_supported() {
    "$1" -c 'import sys; raise SystemExit(0 if (3, 6) <= sys.version_info[:2] <= (3, 14) else 1)' >/dev/null 2>&1
}

find_python() {
    for candidate in python3.14 python3.13 python3.12 python3.11 python3.10 python3.9 python3.8 python3.7 python3.6 python3 python36; do
        if command -v "$candidate" >/dev/null 2>&1; then
            path=$(command -v "$candidate")
            if python_supported "$path"; then
                PYTHON="$path"
                return 0
            fi
        fi
    done
    for path in /usr/bin/python3 /usr/bin/python36 /usr/local/bin/python3 /opt/rh/rh-python36/root/usr/bin/python3; do
        if [ -x "$path" ] && python_supported "$path"; then
            PYTHON="$path"
            if [ "$path" = '/opt/rh/rh-python36/root/usr/bin/python3' ]; then
                SCL_NAME='rh-python36'
            fi
            return 0
        fi
    done
    return 1
}

run_python() {
    if [ -n "$SCL_NAME" ] && command -v scl >/dev/null 2>&1; then
        scl enable "$SCL_NAME" -- "$PYTHON" "$@"
    else
        "$PYTHON" "$@"
    fi
}

systemd_is_active() {
    [ -r /proc/1/comm ] && [ "$(cat /proc/1/comm)" = 'systemd' ]
}

disable_sysv() {
    if [ -x "$SYSV_SCRIPT" ]; then
        "$SYSV_SCRIPT" stop >/dev/null 2>&1 || true
        if command -v update-rc.d >/dev/null 2>&1; then
            update-rc.d -f mirvmon-agent remove >/dev/null 2>&1 || true
        elif command -v chkconfig >/dev/null 2>&1; then
            chkconfig --del mirvmon-agent >/dev/null 2>&1 || true
        fi
        rm -f "$SYSV_SCRIPT"
    fi
}

disable_systemd() {
    if [ -f "$SYSTEMD_UNIT" ] && command -v systemctl >/dev/null 2>&1; then
        systemctl stop mirvmon-agent.service >/dev/null 2>&1 || true
        systemctl disable mirvmon-agent.service >/dev/null 2>&1 || true
        rm -f "$SYSTEMD_UNIT"
        systemctl daemon-reload >/dev/null 2>&1 || true
    fi
}

if command -v apt-get >/dev/null 2>&1; then
    ENV_FILE='/etc/default/mirvmon-agent'
    apt-get update
    apt-get install -y --no-install-recommends ca-certificates curl python3 python3-venv python3-pip
elif command -v dnf >/dev/null 2>&1; then
    ENV_FILE='/etc/sysconfig/mirvmon-agent'
    dnf install -y ca-certificates curl python3 python3-pip
elif command -v yum >/dev/null 2>&1; then
    ENV_FILE='/etc/sysconfig/mirvmon-agent'
    yum install -y ca-certificates curl
    if ! find_python; then
        yum install -y python3 python3-pip >/dev/null 2>&1 || yum install -y python36 python36-pip >/dev/null 2>&1 || yum install -y rh-python36 >/dev/null 2>&1 || fail 'No Python 3.6+ is available from configured yum repositories. Enable a supported EPEL repository or install Software Collections rh-python36, then rerun.'
    fi
else
    fail 'Automatic installation supports apt-get, dnf, and yum only. Install CPython 3.6-3.14, curl, CA certificates, and pip manually.'
fi

find_python || fail 'No supported CPython 3.6-3.14 interpreter was found after package installation.'
PYTHON_MINOR=$(run_python -c 'import sys; print("%s.%s" % sys.version_info[:2])')

if ! id "$AGENT_USER" >/dev/null 2>&1; then
    if command -v useradd >/dev/null 2>&1; then
        useradd --system --home-dir "$STATE_DIR" --create-home --shell /usr/sbin/nologin "$AGENT_USER"
    elif [ "$ENV_FILE" = '/etc/default/mirvmon-agent' ] && command -v adduser >/dev/null 2>&1; then
        adduser --system --home "$STATE_DIR" --disabled-login --shell /usr/sbin/nologin "$AGENT_USER"
    else
        fail 'Neither useradd nor a compatible adduser command is available to create mirvmon-agent.'
    fi
fi

install -d -m 0755 -o root -g root "$INSTALL_DIR" "$RELEASES_DIR"
install -d -m 0750 -o root -g "$AGENT_USER" "$CONFIG_DIR"
install -d -m 0700 -o "$AGENT_USER" -g "$AGENT_USER" "$STATE_DIR"
STAGING_DIR="$RELEASES_DIR/.staging-$$"
mkdir "$STAGING_DIR"
install -d -m 0755 -o root -g root "$STAGING_DIR/mirvmon_agent"

curl --fail --silent --show-error --location "$BASE_URL/get-agent" -o "$STAGING_DIR/agent.py"
for file in __AGENT_FILES__; do
    curl --fail --silent --show-error --location "$BASE_URL/agent/files/$file" -o "$STAGING_DIR/mirvmon_agent/$file"
done
chmod 0755 "$STAGING_DIR/agent.py"

if [ "$PYTHON_MINOR" = '3.6' ]; then
    PIP_SPEC='pip<22'
    PSUTIL_SPEC='psutil==5.9.8'
elif [ "$PYTHON_MINOR" = '3.7' ]; then
    PIP_SPEC='pip<24.1'
    PSUTIL_SPEC='psutil==5.9.8'
else
    PIP_SPEC='pip'
    PSUTIL_SPEC='psutil==7.2.2'
fi

USE_VENV=0
if run_python -m venv "$STAGING_DIR/venv" >/dev/null 2>&1; then
    AGENT_PYTHON="$STAGING_DIR/venv/bin/python"
    "$AGENT_PYTHON" -m ensurepip --upgrade >/dev/null 2>&1 || fail 'The virtual environment has no usable pip. Install the distribution venv/ensurepip package and rerun.'
    "$AGENT_PYTHON" -m pip install --disable-pip-version-check --upgrade "$PIP_SPEC"
    "$AGENT_PYTHON" -m pip install --disable-pip-version-check --only-binary=:all: "$PSUTIL_SPEC" || fail 'No compatible psutil wheel is available. Install a supported CPython build or required compiler toolchain, then rerun.'
    USE_VENV=1
else
    run_python -m ensurepip --upgrade >/dev/null 2>&1 || fail 'Python venv is unavailable and ensurepip is missing. Install the distribution pip package and rerun.'
    run_python -m pip install --disable-pip-version-check --upgrade "$PIP_SPEC"
    run_python -m pip install --disable-pip-version-check --only-binary=:all: --target "$STAGING_DIR/vendor" "$PSUTIL_SPEC" || fail 'No compatible psutil wheel is available. Install a supported CPython build or required compiler toolchain, then rerun.'
fi

if [ ! -f "$CONFIG_DIR/config.json" ]; then
cat > "$CONFIG_DIR/config.json" <<'MIRVMON_CONFIG'
__CONFIG__
MIRVMON_CONFIG
fi
chown root:"$AGENT_USER" "$CONFIG_DIR/config.json"
chmod 0640 "$CONFIG_DIR/config.json"

if [ ! -f "$ENV_FILE" ]; then
cat > "$ENV_FILE" <<'MIRVMON_ENV'
# Optional outbound proxy. Do not put agent credentials here.
# HTTPS_PROXY=http://proxy.example:3128
# HTTP_PROXY=http://proxy.example:3128
# NO_PROXY=localhost,127.0.0.1
MIRVMON_ENV
fi
chmod 0640 "$ENV_FILE"

if [ "$USE_VENV" -eq 1 ]; then
    "$AGENT_PYTHON" -m compileall -q "$STAGING_DIR"
    "$AGENT_PYTHON" "$STAGING_DIR/agent.py" --config "$CONFIG_DIR/config.json" --check
    LAUNCHER_PYTHON="$CURRENT_LINK/venv/bin/python"
    LAUNCHER_PYTHONPATH=''
else
    PYTHONPATH="$STAGING_DIR/vendor" run_python -m compileall -q "$STAGING_DIR"
    PYTHONPATH="$STAGING_DIR/vendor" run_python "$STAGING_DIR/agent.py" --config "$CONFIG_DIR/config.json" --check
    LAUNCHER_PYTHON="$PYTHON"
    LAUNCHER_PYTHONPATH="$CURRENT_LINK/vendor"
fi

# The staged release contains no credentials; it must be readable by the
# dedicated service account before the launcher is switched.
chmod -R a+rX "$STAGING_DIR"

cat > "$INSTALL_DIR/.agent-launcher-$$" <<MIRVMON_LAUNCHER
#!/bin/sh
set -eu
if [ -r "$ENV_FILE" ]; then
    set -a
    . "$ENV_FILE"
    set +a
fi
if [ -n "$LAUNCHER_PYTHONPATH" ]; then
    export PYTHONPATH="$LAUNCHER_PYTHONPATH"
fi
if [ -n "$SCL_NAME" ] && command -v scl >/dev/null 2>&1; then
    exec scl enable "$SCL_NAME" -- "$LAUNCHER_PYTHON" "$CURRENT_LINK/agent.py" "\$@"
fi
exec "$LAUNCHER_PYTHON" "$CURRENT_LINK/agent.py" "\$@"
MIRVMON_LAUNCHER
chmod 0755 "$INSTALL_DIR/.agent-launcher-$$"
ln -s "$STAGING_DIR" "$INSTALL_DIR/.current-$$"
mv -Tf "$INSTALL_DIR/.current-$$" "$CURRENT_LINK"
mv -f "$INSTALL_DIR/.agent-launcher-$$" "$INSTALL_DIR/agent-launcher"

if systemd_is_active; then
    disable_sysv
    cat > "$SYSTEMD_UNIT" <<MIRVMON_SERVICE
[Unit]
Description=MirvMon monitoring agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=mirvmon-agent
Group=mirvmon-agent
EnvironmentFile=-$ENV_FILE
WorkingDirectory=$STATE_DIR
ExecStart=$INSTALL_DIR/agent-launcher --config $CONFIG_DIR/config.json
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
    systemctl status mirvmon-agent.service --no-pager >/dev/null 2>&1 || fail 'systemd could not start mirvmon-agent.service; inspect journalctl -u mirvmon-agent.'
    echo 'MirvMon agent installed. Check: systemctl status mirvmon-agent'
else
    disable_systemd
    cat > "$SYSV_SCRIPT" <<MIRVMON_INIT
#!/bin/sh
### BEGIN INIT INFO
# Provides:          mirvmon-agent
# Required-Start:    \$network \$remote_fs
# Required-Stop:     \$network \$remote_fs
# Default-Start:     2 3 4 5
# Default-Stop:      0 1 6
# Short-Description: MirvMon outbound monitoring agent
### END INIT INFO
NAME='mirvmon-agent'
USER='mirvmon-agent'
LAUNCHER='$INSTALL_DIR/agent-launcher'
CONFIG='$CONFIG_DIR/config.json'
ENV_FILE='$ENV_FILE'
PID_FILE='/var/run/mirvmon-agent.pid'
LOG_FILE='/var/log/mirvmon-agent.log'
# shellcheck disable=SC1090
load_environment() { [ -r "\$ENV_FILE" ] && set -a && . "\$ENV_FILE" && set +a; }
is_running() { [ -s "\$PID_FILE" ] && kill -0 "\$(cat "\$PID_FILE")" 2>/dev/null; }
start() {
    load_environment
    is_running && return 0
    rm -f "\$PID_FILE"
    if command -v start-stop-daemon >/dev/null 2>&1; then
        start-stop-daemon --start --background --make-pidfile --pidfile "\$PID_FILE" --chuid "\$USER" --exec "\$LAUNCHER" -- --config "\$CONFIG"
    else
        su -s /bin/sh -c "exec '\$LAUNCHER' --config '\$CONFIG'" "\$USER" >>"\$LOG_FILE" 2>&1 &
        echo \$! > "\$PID_FILE"
    fi
}
stop() {
    is_running || { rm -f "\$PID_FILE"; return 0; }
    pid=\$(cat "\$PID_FILE"); kill -TERM "\$pid" 2>/dev/null || true; waited=0
    while kill -0 "\$pid" 2>/dev/null && [ "\$waited" -lt 30 ]; do sleep 1; waited=\$((waited + 1)); done
    if kill -0 "\$pid" 2>/dev/null; then
        kill -KILL "\$pid" 2>/dev/null || true
    fi
    rm -f "\$PID_FILE"
}
status() { is_running && { echo "\$NAME is running"; return 0; }; echo "\$NAME is not running"; return 3; }
case "\${1:-}" in start) start ;; stop) stop ;; restart|force-reload) stop; start ;; status) status ;; *) echo "Usage: \$0 {start|stop|restart|force-reload|status}" >&2; exit 2 ;; esac
MIRVMON_INIT
    chmod 0755 "$SYSV_SCRIPT"
    if command -v update-rc.d >/dev/null 2>&1; then
        update-rc.d mirvmon-agent defaults
    elif command -v chkconfig >/dev/null 2>&1; then
        chkconfig --add mirvmon-agent
        chkconfig mirvmon-agent on
    else
        fail 'No supported SysV boot registration command was found (need update-rc.d or chkconfig).'
    fi
    service mirvmon-agent start
    service mirvmon-agent status >/dev/null 2>&1 || fail 'SysV could not start mirvmon-agent; inspect /var/log/mirvmon-agent.log.'
    echo 'MirvMon agent installed. Check: service mirvmon-agent status'
fi
SH;

        return str_replace(
            ['__BASE_URL__', '__AGENT_FILES__', '__CONFIG__'],
            [$baseUrl, implode(' ', self::AGENT_FILES), $config],
            $template
        );
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
        + '"agent_version":"legacy-1.0",' `
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
