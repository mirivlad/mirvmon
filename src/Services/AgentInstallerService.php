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
UPDATE_PATH_UNIT='/etc/systemd/system/mirvmon-agent-update.path'
UPDATE_SERVICE_UNIT='/etc/systemd/system/mirvmon-agent-update.service'
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

systemctl stop mirvmon-agent-update.path >/dev/null 2>&1 || true
systemctl stop mirvmon-agent-update.service >/dev/null 2>&1 || true
systemctl stop mirvmon-agent.service >/dev/null 2>&1 || true
timestamp=$(date -u +%Y%m%d%H%M%S)
[ ! -f "$CONFIG_PATH" ] || cp -p "$CONFIG_PATH" "$CONFIG_PATH.legacy-$timestamp"
[ ! -f "$QUEUE_PATH" ] || cp -p "$QUEUE_PATH" "$QUEUE_PATH.legacy-$timestamp"
install -m 0755 -o root -g root "$STAGING_DIR/mirvmon-agent" "$INSTALL_DIR/mirvmon-agent"
install -m 0640 -o root -g "$AGENT_USER" "$STAGING_DIR/config.json" "$CONFIG_PATH"
install -m 0600 -o "$AGENT_USER" -g "$AGENT_USER" "$STAGING_DIR/queue.json" "$QUEUE_PATH"

# The service name is retained, but legacy runtime files are no longer used.
rm -rf "$INSTALL_DIR/current"
rm -rf "$INSTALL_DIR/releases"
rm -rf "$INSTALL_DIR/venv"
rm -rf "$INSTALL_DIR/mirvmon_agent"
rm -f "$INSTALL_DIR/agent.py" "$INSTALL_DIR/agent-launcher"

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
cat > "$UPDATE_PATH_UNIT" <<'MIRVMON_UPDATE_PATH'
[Unit]
Description=Watch for a MirvMon agent update request

[Path]
PathExists=/var/lib/mirvmon-agent/update-request.json
Unit=mirvmon-agent-update.service

[Install]
WantedBy=multi-user.target
MIRVMON_UPDATE_PATH
cat > "$UPDATE_SERVICE_UNIT" <<'MIRVMON_UPDATE_SERVICE'
[Unit]
Description=Apply a verified MirvMon agent update
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
Group=root
WorkingDirectory=/var/lib/mirvmon-agent
ExecStart=/opt/mirvmon-agent/mirvmon-agent apply-update --config /etc/mirvmon-agent/config.json --request /var/lib/mirvmon-agent/update-request.json --installed /opt/mirvmon-agent/mirvmon-agent
NoNewPrivileges=true
PrivateTmp=true
ReadOnlyDirectories=/
ReadWriteDirectories=/opt/mirvmon-agent /var/lib/mirvmon-agent
MIRVMON_UPDATE_SERVICE
chmod 0644 "$SERVICE_UNIT" "$UPDATE_PATH_UNIT" "$UPDATE_SERVICE_UNIT"
systemctl daemon-reload
systemctl enable mirvmon-agent.service
systemctl enable mirvmon-agent-update.path
systemctl start mirvmon-agent-update.path
systemctl restart mirvmon-agent.service
systemctl is-active --quiet mirvmon-agent.service || fail 'systemd could not start mirvmon-agent.service.'
echo 'MirvMon agent installed. Check: systemctl status mirvmon-agent'
SH
        );
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
