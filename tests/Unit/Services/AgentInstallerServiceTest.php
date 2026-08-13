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

    public function testLinuxInstallerDownloadsNativeBinaryAndMigratesState(): void
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
        self::assertStringContainsString('User=mirvmon-agent', $script);
        self::assertStringContainsString('ExecStart=/opt/mirvmon-agent/mirvmon-agent run', $script);
        self::assertStringContainsString("UPDATE_PATH_UNIT='/etc/systemd/system/mirvmon-agent-update.path'", $script);
        self::assertStringContainsString("UPDATE_SERVICE_UNIT='/etc/systemd/system/mirvmon-agent-update.service'", $script);
        self::assertStringContainsString('PathExists=/var/lib/mirvmon-agent/update-request.json', $script);
        self::assertStringContainsString(
            'ExecStart=/opt/mirvmon-agent/mirvmon-agent apply-update --config /etc/mirvmon-agent/config.json --request /var/lib/mirvmon-agent/update-request.json --installed /opt/mirvmon-agent/mirvmon-agent',
            $script
        );
        self::assertStringContainsString('User=root', $script);
        self::assertStringContainsString('ReadOnlyDirectories=/', $script);
        self::assertStringContainsString('ReadWriteDirectories=/opt/mirvmon-agent /var/lib/mirvmon-agent', $script);
        self::assertStringContainsString('chmod 0644 "$SERVICE_UNIT" "$UPDATE_PATH_UNIT" "$UPDATE_SERVICE_UNIT"', $script);
        self::assertStringContainsString('systemctl enable mirvmon-agent-update.path', $script);
        self::assertStringNotContainsString('python', mb_strtolower($script));
        self::assertStringNotContainsString('?token=', $script);
    }

}
