<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use CurlHandle;
use CurlMultiHandle;
use InvalidArgumentException;
use RuntimeException;

final class ExternalConnectivityProbe
{
    /** @var list<string> */
    private array $targets;
    /** @var Closure(list<string>, float): array<string, bool> */
    private Closure $connector;

    /**
     * @param list<string> $targets host:port targets
     * @param null|Closure(list<string>, float): array<string, bool> $connector
     */
    public function __construct(
        array $targets,
        private readonly int $quorum,
        private readonly float $timeoutSeconds = 1.0,
        ?Closure $connector = null,
    ) {
        $this->targets = array_values(array_filter(array_map('trim', $targets)));
        if ($this->targets === []) {
            throw new InvalidArgumentException('At least one connectivity target is required.');
        }
        if ($quorum < 1 || $quorum > count($this->targets)) {
            throw new InvalidArgumentException('Connectivity quorum is outside target count.');
        }
        if ($timeoutSeconds <= 0 || $timeoutSeconds > 10) {
            throw new InvalidArgumentException('Connectivity timeout must be > 0 and <= 10 seconds.');
        }
        $this->connector = $connector ?? fn (array $targets, float $timeout): array =>
            $this->connectParallel($targets, $timeout);
    }

    /**
     * @return array{available:bool,successes:int,failures:int,successful_targets:list<string>,failed_targets:list<string>}
     */
    public function probe(): array
    {
        $outcomes = ($this->connector)($this->targets, $this->timeoutSeconds);
        $successful = [];
        $failed = [];
        foreach ($this->targets as $target) {
            if (($outcomes[$target] ?? false) === true) {
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

    /**
     * @param list<string> $targets
     * @return array<string, bool>
     */
    private function connectParallel(array $targets, float $timeoutSeconds): array
    {
        if (!function_exists('curl_multi_init')) {
            throw new RuntimeException('cURL multi support is required for connectivity probes.');
        }

        $multi = curl_multi_init();
        /** @var array<int, array{target:string,handle:CurlHandle}> $pending */
        $pending = [];
        $outcomes = [];
        $timeoutMs = max(1, (int) ceil($timeoutSeconds * 1000));
        try {
            foreach ($targets as $target) {
                $handle = curl_init();
                curl_setopt_array($handle, [
                    CURLOPT_URL => 'http://' . $target . '/',
                    CURLOPT_CONNECT_ONLY => true,
                    CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
                    CURLOPT_TIMEOUT_MS => $timeoutMs,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_PROXY => '',
                    CURLOPT_NOPROXY => '*',
                    CURLOPT_RETURNTRANSFER => true,
                ]);
                curl_multi_add_handle($multi, $handle);
                $pending[spl_object_id($handle)] = [
                    'target' => $target,
                    'handle' => $handle,
                ];
            }

            $deadline = microtime(true) + $timeoutSeconds;
            do {
                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
                if ($status !== CURLM_OK) {
                    throw new RuntimeException('Connectivity probe multi-request failed.');
                }

                $this->collectCompleted($multi, $pending, $outcomes);
                if ($pending === [] || $running === 0) {
                    break;
                }

                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                $selected = curl_multi_select($multi, min(0.2, $remaining));
                if ($selected === -1) {
                    usleep((int) min(1000, max(1, $remaining * 1_000_000)));
                }
            } while (microtime(true) < $deadline);

            $this->collectCompleted($multi, $pending, $outcomes);
            foreach ($pending as $entry) {
                $outcomes[$entry['target']] = false;
            }

            return $outcomes;
        } finally {
            foreach ($pending as $entry) {
                curl_multi_remove_handle($multi, $entry['handle']);
            }
            curl_multi_close($multi);
        }
    }

    /**
     * @param array<int, array{target:string,handle:CurlHandle}> $pending
     * @param array<string, bool> $outcomes
     */
    private function collectCompleted(
        CurlMultiHandle $multi,
        array &$pending,
        array &$outcomes
    ): void {
        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $id = spl_object_id($handle);
            if (!isset($pending[$id])) {
                continue;
            }
            $target = $pending[$id]['target'];
            $outcomes[$target] = ($info['result'] ?? CURLE_FAILED_INIT) === CURLE_OK;
            curl_multi_remove_handle($multi, $handle);
            unset($pending[$id]);
        }
    }
}
