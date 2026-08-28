<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;

final class WebsiteAvailabilityRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(
        int $websiteId,
        int $endpointId,
        string $state,
        DateTimeImmutable $occurredAt,
        ?int $alertId,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO website_availability_events
                (website_id, endpoint_id, state, occurred_at, alert_id)
             VALUES (:website_id, :endpoint_id, :state, :occurred_at, :alert_id)'
        );
        $statement->execute([
            'website_id' => $websiteId,
            'endpoint_id' => $endpointId,
            'state' => $state,
            'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
            'alert_id' => $alertId,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function timeline(
        int $websiteId,
        ?int $endpointId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $where = ['website_id = :website_id', 'occurred_at >= :from', 'occurred_at < :to'];
        $parameters = [
            'website_id' => $websiteId,
            'from' => $from->format(DateTimeInterface::ATOM),
            'to' => $to->format(DateTimeInterface::ATOM),
        ];
        if ($endpointId !== null) {
            $where[] = 'endpoint_id = :endpoint_id';
            $parameters['endpoint_id'] = $endpointId;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, website_id, endpoint_id, state, occurred_at, alert_id
             FROM website_availability_events
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY occurred_at, id'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }
}
