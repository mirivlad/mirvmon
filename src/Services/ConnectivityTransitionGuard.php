<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Confirm connectivity transitions without treating one failed probe as an outage.
 *
 * A pending transition does not refresh the last confirmed state. Consumers that
 * require fresh connectivity will therefore see "unknown" if checks keep failing.
 */
final class ConnectivityTransitionGuard
{
    public const OFFLINE_CONFIRMATIONS = 3;
    public const ONLINE_CONFIRMATIONS = 2;

    private ?string $pendingState = null;
    private int $pendingCount = 0;

    public function __construct(private ?string $confirmedState = null)
    {
    }

    /**
     * @return null|string Confirmed state to record, or null while a transition is pending.
     */
    public function observe(bool $available): ?string
    {
        $candidate = $available ? 'online' : 'offline';
        if ($candidate === $this->confirmedState) {
            $this->pendingState = null;
            $this->pendingCount = 0;
            return $candidate;
        }

        if ($candidate !== $this->pendingState) {
            $this->pendingState = $candidate;
            $this->pendingCount = 0;
        }
        ++$this->pendingCount;

        $required = $candidate === 'offline'
            ? self::OFFLINE_CONFIRMATIONS
            : self::ONLINE_CONFIRMATIONS;
        if ($this->pendingCount < $required) {
            return null;
        }

        $this->confirmedState = $candidate;
        $this->pendingState = null;
        $this->pendingCount = 0;

        return $candidate;
    }

    public function confirmedState(): ?string
    {
        return $this->confirmedState;
    }
}
