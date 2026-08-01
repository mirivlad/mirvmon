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

    public function testLinuxInstallerUsesOriginWithoutTokenizedDownloads(): void
    {
        $script = $this->installer->linux(
            'https://monitor.example',
            str_repeat('a', 64)
        );

        self::assertStringContainsString(
            'https://monitor.example/api/v1/metrics',
            $script
        );
        self::assertStringContainsString('User=mirvmon-agent', $script);
        self::assertStringContainsString('useradd --system', $script);
        self::assertStringNotContainsString('requests==', $script);
        self::assertStringContainsString('find_python()', $script);
        self::assertStringContainsString('python36', $script);
        self::assertStringContainsString('rh-python36', $script);
        self::assertStringContainsString('/etc/init.d/mirvmon-agent', $script);
        self::assertStringContainsString('force-reload', $script);
        self::assertStringContainsString('/proc/1/comm', $script);
        self::assertStringContainsString('--check', $script);
        self::assertStringContainsString('yum install', $script);
        self::assertStringContainsString('rh-python36', $script);
        self::assertStringNotContainsString('apk ', $script);
        self::assertStringContainsString('psutil==5.9.8', $script);
        self::assertStringContainsString('psutil==7.2.2', $script);
        self::assertStringContainsString('chmod -R a+rX "$STAGING_DIR"', $script);
        self::assertStringContainsString('mv -Tf "$INSTALL_DIR/.current-$$" "$CURRENT_LINK"', $script);
        self::assertStringNotContainsString('?token=', $script);
        self::assertStringNotContainsString('mon.mirv.top', $script);
    }

    public function testWindowsInstallerUsesProgramDataAndTlsVerification(): void
    {
        $script = $this->installer->windowsPowerShell(
            'https://windows-monitor.example',
            str_repeat('b', 64)
        );

        self::assertStringContainsString('$env:ProgramData', $script);
        self::assertStringContainsString('MirvMon\\Agent', $script);
        self::assertStringContainsString('"verify_tls": true', $script);
        self::assertStringContainsString(
            'https://windows-monitor.example/api/v1/agent/config',
            $script
        );
        self::assertStringNotContainsString('?token=', $script);
        self::assertStringNotContainsString('mon.mirv.top', $script);
    }

    public function testBatchWrapperDoesNotExposeTheAgentTokenAsAnArgument(): void
    {
        $batch = $this->installer->windowsBatch(
            'https://monitor.example',
            str_repeat('c', 64)
        );

        self::assertStringContainsString('-EncodedCommand', $batch);
        self::assertStringNotContainsString(str_repeat('c', 64), $batch);
        self::assertStringNotContainsString('?token=', $batch);
    }

    public function testWindowsInstallerOutputsAreRegressionLocked(): void
    {
        $token = str_repeat('b', 64);
        $baseUrl = 'https://windows-monitor.example';

        self::assertSame(
            'df6b4bcfce01e6627209a072e7344fcbf85758509a895ad619777be540e2fca2',
            hash('sha256', $this->installer->windowsPowerShell($baseUrl, $token))
        );
        self::assertSame(
            'a027410918a345ca301856a5985b68edffe98a2b5634d42fa013fa931a5e844f',
            hash('sha256', $this->installer->windowsBatch($baseUrl, $token))
        );
        self::assertSame(
            'b81f1d924e40c9a934738d0b18fdc5ca6b103c667f4642b5822f5c413abd6b22',
            hash('sha256', $this->installer->windowsLegacyPowerShell($baseUrl, $token))
        );
        self::assertSame(
            '981de5177e983463440c93fcd606b265c8f34ce6deaa08185c2d575afa74ab5b',
            hash('sha256', $this->installer->windowsLegacyBatch($baseUrl, $token))
        );
    }

    public function testLinuxInstallerExecutesSafelyAsASysVUpgrade(): void
    {
        $root = sys_get_temp_dir() . '/mirvmon-installer-' . bin2hex(random_bytes(8));
        $bin = $root . '/bin';
        mkdir($bin, 0700, true);
        $configDirectory = $root . '/etc/mirvmon-agent';
        $stateDirectory = $root . '/var/lib/mirvmon-agent';
        mkdir($configDirectory, 0700, true);
        mkdir($stateDirectory, 0700, true);
        mkdir($root . '/default', 0700, true);
        mkdir($root . '/init.d', 0700, true);
        file_put_contents($configDirectory . '/config.json', "{\"token\":\"existing\"}\n");
        file_put_contents($stateDirectory . '/queue.json', "[{\"sample_id\":\"queued\"}]\n");

        $script = $this->installer->linux('https://monitor.example', str_repeat('a', 64));
        $script = str_replace(
            [
                "INSTALL_DIR='/opt/mirvmon-agent'",
                "CONFIG_DIR='/etc/mirvmon-agent'",
                "STATE_DIR='/var/lib/mirvmon-agent'",
                "SYSTEMD_UNIT='/etc/systemd/system/mirvmon-agent.service'",
                "SYSV_SCRIPT='/etc/init.d/mirvmon-agent'",
                "ENV_FILE='/etc/default/mirvmon-agent'",
                'for candidate in python3.14 python3.13 python3.12 python3.11 python3.10 python3.9 python3.8 python3.7 python3.6 python3 python36; do',
            ],
            [
                "INSTALL_DIR='$root/opt/mirvmon-agent'",
                "CONFIG_DIR='$configDirectory'",
                "STATE_DIR='$stateDirectory'",
                "SYSTEMD_UNIT='$root/systemd/mirvmon-agent.service'",
                "SYSV_SCRIPT='$root/init.d/mirvmon-agent'",
                "ENV_FILE='$root/default/mirvmon-agent'",
                'for candidate in python3; do',
            ],
            $script
        );
        $scriptPath = $root . '/installer.sh';
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0700);

        $this->writeFakeCommand($bin, 'id', <<<'SH'
#!/bin/sh
[ "$1" = '-u' ] && { echo 0; exit 0; }
exit 1
SH);
        $this->writeFakeCommand($bin, 'apt-get', "#!/bin/sh\nexit 0\n");
        $this->writeFakeCommand($bin, 'useradd', "#!/bin/sh\nexit 0\n");
        $this->writeFakeCommand($bin, 'chown', "#!/bin/sh\nexit 0\n");
        $this->writeFakeCommand($bin, 'install', <<<'SH'
#!/bin/sh
for argument in "$@"; do
    case "$argument" in -*) ;; *) target="$argument" ;; esac
done
mkdir -p "$target"
SH);
        $this->writeFakeCommand($bin, 'cat', <<<'SH'
#!/bin/sh
if [ "$1" = '/proc/1/comm' ]; then echo init; else /bin/cat "$@"; fi
SH);
        $this->writeFakeCommand($bin, 'curl', <<<'SH'
#!/bin/sh
while [ "$#" -gt 0 ]; do
    if [ "$1" = '-o' ]; then output="$2"; shift 2; continue; fi
    shift
done
printf '%s\n' '# generated source' > "$output"
SH);
        $this->writeFakeCommand($bin, 'python3', <<<'SH'
#!/bin/sh
if [ "$1" = '-c' ]; then
    case "$2" in *version_info*) echo 3.6 ;; esac
    exit 0
fi
if [ "$1" = '-m' ] && [ "$2" = 'venv' ]; then
    mkdir -p "$3/bin"
    cp "$0" "$3/bin/python"
fi
exit 0
SH);
        $this->writeFakeCommand($bin, 'update-rc.d', "#!/bin/sh\nexit 0\n");
        $this->writeFakeCommand($bin, 'service', "#!/bin/sh\nexit 0\n");

        $environment = [
            'PATH' => $bin . ':' . getenv('PATH'),
            'HOME' => $root,
        ];
        $process = proc_open([$scriptPath], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $root, $environment);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame("{\"token\":\"existing\"}\n", file_get_contents($configDirectory . '/config.json'));
        self::assertSame("[{\"sample_id\":\"queued\"}]\n", file_get_contents($stateDirectory . '/queue.json'));
        self::assertSame(0640, fileperms($configDirectory . '/config.json') & 0777);
        self::assertFileExists($root . '/init.d/mirvmon-agent');
        self::assertSame(
            '',
            trim((string) shell_exec(
                'shellcheck -s sh ' . escapeshellarg($root . '/init.d/mirvmon-agent') . ' 2>&1'
            ))
        );
        self::assertStringNotContainsString(str_repeat('a', 64), $stdout . $stderr);

        $firstRelease = readlink($root . '/opt/mirvmon-agent/current');
        self::assertIsString($firstRelease);
        $secondProcess = proc_open([$scriptPath], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $secondPipes, $root, $environment);
        self::assertIsResource($secondProcess);
        stream_get_contents($secondPipes[1]);
        $secondError = stream_get_contents($secondPipes[2]);
        fclose($secondPipes[1]);
        fclose($secondPipes[2]);
        self::assertSame(0, proc_close($secondProcess), $secondError);
        self::assertNotSame($firstRelease, readlink($root . '/opt/mirvmon-agent/current'));

        exec('rm -rf ' . escapeshellarg($root));
    }

    private function writeFakeCommand(string $directory, string $name, string $script): void
    {
        $path = $directory . '/' . $name;
        file_put_contents($path, $script);
        chmod($path, 0700);
    }

    public function testLegacyInstallerAvoidsEveryApiNewerThanPowerShell2(): void
    {
        $script = $this->installer->windowsLegacyPowerShell(
            'https://legacy-monitor.example',
            str_repeat('d', 64)
        );

        foreach ([
            'Invoke-WebRequest',
            'Invoke-RestMethod',
            'ConvertTo-Json',
            'ConvertFrom-Json',
            'Register-ScheduledTask',
            'New-ScheduledTaskAction',
            'Get-CimInstance',
        ] as $unavailable) {
            self::assertStringNotContainsString($unavailable, $script, $unavailable);
        }

        self::assertStringContainsString('schtasks.exe /Create', $script);
        self::assertStringContainsString('Get-WmiObject Win32_OperatingSystem', $script);
        self::assertStringContainsString('[Net.HttpWebRequest]::Create', $script);
        // Tls12 is spelled numerically because the enum member is missing on
        // the .NET versions these systems ship with.
        self::assertStringContainsString('[Net.SecurityProtocolType]3072', $script);
        self::assertStringNotContainsString('python', mb_strtolower($script));
    }

    public function testLegacyCollectorSendsTheSameEnvelopeContract(): void
    {
        $script = $this->installer->windowsLegacyPowerShell(
            'https://legacy-monitor.example',
            str_repeat('e', 64)
        );

        self::assertStringContainsString(
            'https://legacy-monitor.example/api/v1/metrics',
            $script
        );
        self::assertStringContainsString('"version":2,', $script);
        self::assertStringContainsString('"agent_version":"legacy-1.0",', $script);
        self::assertStringContainsString("'yyyy-MM-ddTHH:mm:ss') + 'Z'", $script);
        foreach (['cpu_load', 'ram_used', 'ram_total_gb', 'uptime', 'disk_used'] as $metric) {
            self::assertStringContainsString($metric, $script);
        }
        self::assertStringContainsString("'net_in_'", $script);
        self::assertStringContainsString("'disk_total_gb_'", $script);
        // The token belongs in the request body, never in a command line.
        self::assertStringNotContainsString('?token=', $script);
        self::assertStringContainsString(str_repeat('e', 64), $script);
    }

    public function testLegacyBatchHidesTheTokenAndElevates(): void
    {
        $batch = $this->installer->windowsLegacyBatch(
            'https://legacy-monitor.example',
            str_repeat('f', 64)
        );

        self::assertStringContainsString('net session', $batch);
        self::assertStringContainsString('-EncodedCommand', $batch);
        self::assertStringNotContainsString(str_repeat('f', 64), $batch);
    }
}
