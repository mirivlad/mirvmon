<?php

declare(strict_types=1);

namespace App\Services;

use Closure;

final class ExternalConnectivityProbe
{
    /** @var list<string> */
    private array $targets;
    private Closure $connector;

    /**
     * @param list<string> $targets host:port targets
     * @param null|Closure(string, float): bool $connector
     */
    public function __construct(
        array $targets,
        private readonly int $quorum,
        private readonly float $timeoutSeconds = 1.0,
        ?Closure $connector = null,
    ) {
        $this->targets = array_values(array_filter(array_map('trim', $targets)));
        if ($this->targets === []) {
            throw new \InvalidArgumentException('At least one connectivity target is required.');
        }
        if ($quorum < 1 || $quorum > count($this->targets)) {
            throw new \InvalidArgumentException('Connectivity quorum is outside target count.');
        }
        if ($timeoutSeconds <= 0 || $timeoutSeconds > 10) {
            throw new \InvalidArgumentException('Connectivity timeout must be > 0 and <= 10 seconds.');
        }
        $this->connector = $connector ?? static function (string $target, float $timeout): bool {
            $socket = @stream_socket_client(
                'tcp://' . $target,
                $errorCode,
                $errorMessage,
                $timeout,
                STREAM_CLIENT_CONNECT,
            );
            if ($socket === false) {
                return false;
            }
            fclose($socket);
            return true;
        };
    }

    /**
     * @return array{available:bool,successes:int,failures:int,successful_targets:list<string>,failed_targets:list<string>}
     */
    public function probe(): array
    {
        $successful = [];
        $failed = [];
        foreach ($this->targets as $target) {
            if (($this->connector)($target, $this->timeoutSeconds)) {
                $successful[] = $target;
            } else {
                $failed[] = $target;
            }
        }

        return [
            'available' => count($successful) >= $this->quorum,
            'successes' => count($successful),
            'failures' => count($failed),
            'successful_targets' => $successful,
            'failed_targets' => $failed,
        ];
    }
}
