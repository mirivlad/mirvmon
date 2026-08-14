<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AgentInstallerService;
use PHPUnit\Framework\TestCase;

final class AgentInstallerServiceTest extends TestCase
{
    private AgentInstallerService $installer;

    protected function setUp(): void
    {
        $this->installer = new AgentInstallerService();
    }

    public function testLinuxInstallerDownloadsNativeBinaryMigratesStateAndSupportsSystemdAndSysV(): void
    {
        $script = $this->installer->linux(
            'https://monitor.example',
            str_repeat('a', 64)
        );

        self::assertStringContainsString(
            'https://monitor.example/agent/binaries/linux-amd64',
            $script
        );
        self::assertStringContainsString('migrate --source-config', $script);
        self::assertStringContainsString('rm -rf "$INSTALL_DIR/current"', $script);
        self::assertStringContainsString('rm -rf "$INSTALL_DIR/releases"', $script);
        self::assertStringContainsString('rm -rf "$INSTALL_DIR/venv"', $script);
        self::assertStringContainsString('rm -f "$INSTALL_DIR/agent.py" "$INSTALL_DIR/agent-launcher"', $script);

        // The installer carries its own fallback trust roots, so bootstrap
        // downloads do not depend on a legacy host having a current CA store.
        self::assertStringContainsString('CA_BUNDLE="$STAGING_DIR/ca-bundle.pem"', $script);
        self::assertStringContainsString('MIRVMON_CA_BUNDLE', $script);
        self::assertStringContainsString('# ISRG Root X1', $script);
        self::assertStringContainsString('# ISRG Root X2', $script);
        self::assertStringContainsString('--cacert "$CA_BUNDLE"', $script);
        self::assertStringContainsString('--ca-certificate="$CA_BUNDLE"', $script);
        self::assertStringNotContainsString('--insecure', $script);

        // systemd remains supported, but only when it is actually PID 1.
        self::assertStringContainsString('/proc/1/comm', $script);
        self::assertStringContainsString("= 'systemd'", $script);
        self::assertStringContainsString("INIT_SYSTEM='systemd'", $script);
        self::assertStringContainsString('User=mirvmon-agent', $script);
        self::assertStringContainsString('ExecStart=/opt/mirvmon-agent/mirvmon-agent run', $script);
        self::assertStringContainsString("UPDATE_PATH_UNIT='/etc/systemd/system/mirvmon-agent-update.path'", $script);
        self::assertStringContainsString("UPDATE_SERVICE_UNIT='/etc/systemd/system/mirvmon-agent-update.service'", $script);
        self::assertStringContainsString('PathExists=/var/lib/mirvmon-agent/update-request.json', $script);
        self::assertStringContainsString('systemctl enable mirvmon-agent-update.path', $script);

        // Non-systemd Linux gets an LSB-compatible service and a privileged
        // fixed-purpose update watcher rather than losing self-update support.
        self::assertStringContainsString("INIT_SCRIPT='/etc/init.d/mirvmon-agent'", $script);
        self::assertStringContainsString("UPDATER_INIT_SCRIPT='/etc/init.d/mirvmon-agent-updater'", $script);
        self::assertStringContainsString('### BEGIN INIT INFO', $script);
        self::assertStringContainsString('# Provides:          mirvmon-agent', $script);
        self::assertStringContainsString('start-stop-daemon --start', $script);
        self::assertStringContainsString('update-rc.d mirvmon-agent defaults', $script);
        self::assertStringContainsString('chkconfig --add mirvmon-agent', $script);
        self::assertStringContainsString('service mirvmon-agent status', $script);
        self::assertStringContainsString(
            '/opt/mirvmon-agent/mirvmon-agent apply-update --config /etc/mirvmon-agent/config.json --request /var/lib/mirvmon-agent/update-request.json --installed /opt/mirvmon-agent/mirvmon-agent',
            $script
        );

        self::assertStringContainsString('User=root', $script);
        self::assertStringContainsString('ReadOnlyDirectories=/', $script);
        self::assertStringContainsString('ReadWriteDirectories=/opt/mirvmon-agent /var/lib/mirvmon-agent', $script);
        self::assertStringNotContainsString('A systemd host is required.', $script);
        self::assertStringNotContainsString('python', mb_strtolower($script));
        self::assertStringNotContainsString('?token=', $script);
    }
}
