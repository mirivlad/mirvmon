<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class DomainWhoisFixture
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private ?string $profilesPath = null;

    public function start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve WHOIS fixture port: ' . $errorMessage);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $script = dirname(__DIR__) . '/Fixtures/Websites/whois-server.php';
        $this->process = proc_open(
            [PHP_BINARY, $script, (string) $port],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            dirname(__DIR__, 2),
        );
        if (!is_resource($this->process)) {
            throw new RuntimeException('Unable to start WHOIS fixture.');
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        $deadline = microtime(true) + 3.0;
        do {
            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($client)) {
                fclose($client);
                $path = tempnam(sys_get_temp_dir(), 'mirvmon-whois-');
                if ($path === false) {
                    throw new RuntimeException('Unable to create WHOIS profile fixture.');
                }
                $this->profilesPath = $path;
                file_put_contents($path, "<?php\nreturn ['version' => 1, 'zones' => ['test' => ['server' => '127.0.0.1:" . $port . "', 'patterns' => ['/^Expiration Date:\\\\s*(.+)$/mi']]]];\n");

                return;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('WHOIS fixture did not start.');
    }

    public function profilesPath(): string
    {
        return $this->profilesPath ?? throw new RuntimeException('WHOIS fixture did not start.');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
        if ($this->profilesPath !== null && is_file($this->profilesPath)) {
            unlink($this->profilesPath);
        }
        $this->process = null;
        $this->pipes = [];
        $this->profilesPath = null;
    }
}
