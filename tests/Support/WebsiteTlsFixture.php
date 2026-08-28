<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class WebsiteTlsFixture
{
    /** @var list<resource> */
    private array $processes = [];

    /** @var list<array<int, resource>> */
    private array $pipes = [];

    public function start(string $certificate): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Unable to reserve TLS fixture port: ' . $errorMessage);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $script = dirname(__DIR__) . '/Fixtures/Websites/tls-server.php';
        $process = proc_open(
            [PHP_BINARY, $script, (string) $port, $certificate],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start TLS fixture server.');
        }
        fclose($pipes[0]);
        unset($pipes[0]);
        $this->processes[] = $process;
        $this->pipes[] = $pipes;
        $deadline = microtime(true) + 2.0;
        do {
            $client = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.05);
            if (is_resource($client)) {
                fclose($client);
                usleep(50000);

                return $port;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('TLS fixture server did not start.');
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
    }
}
