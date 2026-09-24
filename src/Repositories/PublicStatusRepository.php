<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class PublicStatusRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{servers:list<array<string,mixed>>,websites:list<array<string,mixed>>} */
    public function choices(): array
    {
        $published = [];
        foreach ($this->pdo->query('SELECT server_id, website_id, display_name FROM public_status_items')->fetchAll() as $row) {
            $key = $row['server_id'] === null ? 'website:' . $row['website_id'] : 'server:' . $row['server_id'];
            $published[$key] = (string) $row['display_name'];
        }
        $servers = $this->pdo->query('SELECT id, name FROM servers WHERE is_active = TRUE ORDER BY name, id')->fetchAll();
        $websites = $this->pdo->query('SELECT id, name FROM websites WHERE is_active = TRUE ORDER BY name, id')->fetchAll();
        foreach ($servers as &$row) {
            $key = 'server:' . $row['id'];
            $row['published'] = isset($published[$key]);
            $row['display_name'] = $published[$key] ?? (string) $row['name'];
        }
        unset($row);
        foreach ($websites as &$row) {
            $key = 'website:' . $row['id'];
            $row['published'] = isset($published[$key]);
            $row['display_name'] = $published[$key] ?? (string) $row['name'];
        }
        unset($row);
        return ['servers' => $servers, 'websites' => $websites];
    }

    /** @param array<string,mixed> $selection */
    public function replace(array $selection): void
    {
        $items = [];
        foreach (['server', 'website'] as $type) {
            $rows = $selection[$type] ?? [];
            if (!is_array($rows)) {
                throw new InvalidArgumentException('Invalid status page selection.');
            }
            foreach ($rows as $rawId => $row) {
                if (!is_array($row) || ($row['enabled'] ?? null) !== '1') {
                    continue;
                }
                $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $name = trim((string) ($row['display_name'] ?? ''));
                if ($id === false || $name === '' || mb_strlen($name) > 100) {
                    throw new InvalidArgumentException('Invalid public status item.');
                }
                $items[] = ['type' => $type, 'id' => (int) $id, 'name' => $name];
            }
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT public_status_replace');
        }
        try {
            $this->pdo->exec('DELETE FROM public_status_items');
            $insertServer = $this->pdo->prepare(
                'INSERT INTO public_status_items(server_id, display_name, sort_order)
                 SELECT id, :display_name, :sort_order FROM servers WHERE id = :id AND is_active = TRUE'
            );
            $insertWebsite = $this->pdo->prepare(
                'INSERT INTO public_status_items(website_id, display_name, sort_order)
                 SELECT id, :display_name, :sort_order FROM websites WHERE id = :id AND is_active = TRUE'
            );
            foreach ($items as $position => $item) {
                $insert = $item['type'] === 'server' ? $insertServer : $insertWebsite;
                $insert->execute([
                    'display_name' => $item['name'], 'sort_order' => $position, 'id' => $item['id'],
                ]);
                if ($insert->rowCount() !== 1) {
                    throw new InvalidArgumentException('Selected object is unavailable.');
                }
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT public_status_replace');
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT public_status_replace');
            }
            throw $exception;
        }
    }

    /** @return list<array{display_name:string,type:string,status:string}> */
    public function published(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $statement = $this->pdo->query(
            'SELECT items.display_name, items.sort_order, items.id,
                    servers.last_metrics_at, servers.offline_timeout_seconds,
                    agent_configs.interval_seconds, availability.state,
                    CASE WHEN EXISTS(SELECT 1 FROM alerts WHERE alerts.server_id = servers.id
                           AND alerts.resolved = FALSE) THEN 1 ELSE 0 END AS has_incident
             FROM public_status_items AS items
             INNER JOIN servers ON servers.id = items.server_id AND servers.is_active = TRUE
             LEFT JOIN agent_configs ON agent_configs.server_id = servers.id
             LEFT JOIN server_availability_state AS availability ON availability.server_id = servers.id'
        );
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $last = $row['last_metrics_at'] === null ? null : new DateTimeImmutable((string) $row['last_metrics_at']);
            $staleAfter = max(300, 3 * (int) ($row['interval_seconds'] ?? 60));
            $age = $last === null ? PHP_INT_MAX : $now->getTimestamp() - $last->getTimestamp();
            $status = $age > $staleAfter || $row['state'] === null
                ? 'unknown'
                : ($row['state'] === 'offline' ? 'outage' : ((int) $row['has_incident'] === 1 ? 'degraded' : 'operational'));
            $result[] = [
                'display_name' => (string) $row['display_name'], 'type' => 'server',
                'status' => $status, 'sort_order' => (int) $row['sort_order'], 'id' => (int) $row['id'],
            ];
        }

        $statement = $this->pdo->query(
            'SELECT items.display_name, items.sort_order, items.id,
                    CASE WHEN websites.is_active THEN 1 ELSE 0 END AS is_active, website_state.status,
                    endpoints.interval_seconds, websites.default_interval_seconds,
                    endpoint_state.last_sample_at
             FROM public_status_items AS items
             INNER JOIN websites ON websites.id = items.website_id
             LEFT JOIN website_state ON website_state.website_id = websites.id
             LEFT JOIN website_endpoints AS endpoints
               ON endpoints.id = website_state.primary_endpoint_id
             LEFT JOIN website_endpoint_state AS endpoint_state
               ON endpoint_state.endpoint_id = endpoints.id'
        );
        foreach ($statement->fetchAll() as $row) {
            $last = $row['last_sample_at'] === null ? null : new DateTimeImmutable((string) $row['last_sample_at']);
            $interval = (int) ($row['interval_seconds'] ?? $row['default_interval_seconds']);
            $staleAfter = max(300, 3 * $interval);
            $age = $last === null ? PHP_INT_MAX : $now->getTimestamp() - $last->getTimestamp();
            $status = match (true) {
                (int) $row['is_active'] === 0 => 'maintenance',
                $age > $staleAfter => 'unknown',
                $row['status'] === 'healthy' => 'operational',
                in_array($row['status'], ['warning', 'slow', 'degraded'], true) => 'degraded',
                in_array($row['status'], ['critical', 'unavailable', 'problem'], true) => 'outage',
                $row['status'] === 'paused' => 'maintenance',
                default => 'unknown',
            };
            $result[] = [
                'display_name' => (string) $row['display_name'], 'type' => 'website',
                'status' => $status, 'sort_order' => (int) $row['sort_order'], 'id' => (int) $row['id'],
            ];
        }
        usort($result, static fn (array $left, array $right): int =>
            [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']]);
        return array_map(static fn (array $row): array => [
            'display_name' => $row['display_name'], 'type' => $row['type'], 'status' => $row['status'],
        ], $result);
    }
}
