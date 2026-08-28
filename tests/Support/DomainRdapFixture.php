<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class DomainRdapFixture
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private ?string $bootstrapPath = null;

    public function start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve RDAP fixture port: ' . $errorMessage);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $router = dirname(__DIR__) . '/Fixtures/Websites/rdap-router.php';
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            dirname(__DIR__, 2),
        );
        if (!is_resource($this->process)) {
            throw new RuntimeException('Unable to start RDAP fixture.');
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
        $deadline = microtime(true) + 3.0;
        do {
            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($client)) {
                fclose($client);
                $path = tempnam(sys_get_temp_dir(), 'mirvmon-rdap-');
                if ($path === false) {
                    throw new RuntimeException('Unable to create RDAP bootstrap fixture.');
                }
                $this->bootstrapPath = $path;
                file_put_contents($path, json_encode([
                    'services' => [
                        [['com'], ['http://127.0.0.1:' . $port . '/rdap/']],
                        [['example.com'], ['http://127.0.0.1:' . $port . '/specific/']],
                    ],
                ], JSON_THROW_ON_ERROR));

                return;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('RDAP fixture did not start.');
    }

    public function bootstrapPath(): string
    {
        return $this->bootstrapPath ?? throw new RuntimeException('RDAP fixture did not start.');
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
        if ($this->bootstrapPath !== null && is_file($this->bootstrapPath)) {
            unlink($this->bootstrapPath);
        }
        $this->process = null;
        $this->pipes = [];
        $this->bootstrapPath = null;
    }
}
