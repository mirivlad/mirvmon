<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class WebsiteHttpFixture
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<array<int, resource>> */
    private array $pipes = [];

    /** @var list<string> */
    private array $origins = [];

    public function start(): void
    {
        $router = dirname(__DIR__) . '/Fixtures/Websites/http-router.php';
        $this->origins[] = $this->startServer($router);
        $this->origins[] = $this->startServer($router);
    }

    public function firstOrigin(string $path = ''): string
    {
        return $this->origins[0] . $path;
    }

    public function secondOrigin(string $path = ''): string
    {
        return $this->origins[1] . $path;
    }

    public function stop(): void
    {
        foreach ($this->processes as $index => $process) {
            if (!is_resource($process)) {
                continue;
            }
            proc_terminate($process);
            foreach ($this->pipes[$index] ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
        $this->processes = [];
        $this->pipes = [];
        $this->origins = [];
    }

    private function startServer(string $router): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve HTTP fixture port: ' . $errorMessage);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start HTTP fixture server.');
        }
        fclose($pipes[0]);
        unset($pipes[0]);

        $deadline = microtime(true) + 3.0;
        do {
            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($client)) {
                fclose($client);
                $this->processes[] = $process;
                $this->pipes[] = $pipes;

                return 'http://127.0.0.1:' . $port;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
        throw new RuntimeException('HTTP fixture server did not start.');
    }
}
