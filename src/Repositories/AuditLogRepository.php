<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use PDO;

final class AuditLogRepository
{
    public const PAGE_SIZE = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function append(
        ?int $actorUserId,
        string $actorUsername,
        ?string $actorRole,
        string $action,
        string $objectType,
        ?string $objectId,
        ?string $objectLabel,
        string $description,
        array $metadata = []
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (
                actor_user_id,
                actor_username,
                actor_role,
                action,
                object_type,
                object_id,
                object_label,
                description,
                metadata
             ) VALUES (
                :actor_user_id,
                :actor_username,
                :actor_role,
                :action,
                :object_type,
                :object_id,
                :object_label,
                :description,
                CAST(:metadata AS jsonb)
             ) RETURNING id'
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'actor_username' => $actorUsername,
            'actor_role' => $actorRole,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'object_label' => $objectLabel,
            'description' => $description,
            'metadata' => json_encode(
                $metadata,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{actor:?string,action:?string,object_type:?string,object_id:?string,from:?string,to:?string,q:?string}
     */
    public function filters(array $input): array
    {
        return [
            'actor' => $this->boundedString($input['actor'] ?? null, 80),
            'action' => $this->identifier($input['action'] ?? null, 80),
            'object_type' => $this->identifier($input['object_type'] ?? null, 40),
            'object_id' => $this->boundedString($input['object_id'] ?? null, 100),
            'from' => $this->dateBoundary($input['from'] ?? null, false),
            'to' => $this->dateBoundary($input['to'] ?? null, true),
            'q' => $this->boundedString($input['q'] ?? null, 200),
        ];
    }

    /**
     * @param array{actor:?string,action:?string,object_type:?string,object_id:?string,from:?string,to:?string,q:?string} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public function page(array $filters, int $page): array
    {
        [$where, $parameters] = $this->where($filters);
        $count = $this->pdo->prepare('SELECT count(*) FROM audit_log' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));

        $statement = $this->pdo->prepare(
            'SELECT * FROM audit_log'
            . $where
            . ' ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':limit', self::PAGE_SIZE, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PAGE_SIZE,
        ];
    }

    /** @return array{actors:list<string>,actions:list<string>,object_types:list<string>} */
    public function filterOptions(): array
    {
        return [
            'actors' => $this->distinctStrings('actor_username'),
            'actions' => $this->distinctStrings('action'),
            'object_types' => $this->distinctStrings('object_type'),
        ];
    }

    /**
     * @param array{actor:?string,action:?string,object_type:?string,object_id:?string,from:?string,to:?string,q:?string} $filters
     * @return array{string,array<string,string>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $parameters = [];
        if ($filters['actor'] !== null) {
            $conditions[] = 'actor_username = :actor';
            $parameters['actor'] = $filters['actor'];
        }
        if ($filters['action'] !== null) {
            $conditions[] = 'action = :action';
            $parameters['action'] = $filters['action'];
        }
        if ($filters['object_type'] !== null) {
            $conditions[] = 'object_type = :object_type';
            $parameters['object_type'] = $filters['object_type'];
        }
        if ($filters['object_id'] !== null) {
            $conditions[] = 'object_id = :object_id';
            $parameters['object_id'] = $filters['object_id'];
        }
        if ($filters['from'] !== null) {
            $conditions[] = 'occurred_at >= CAST(:from AS timestamptz)';
            $parameters['from'] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $conditions[] = 'occurred_at < CAST(:to AS timestamptz)';
            $parameters['to'] = $filters['to'];
        }
        if ($filters['q'] !== null) {
            $conditions[] = '(description ILIKE :q OR object_label ILIKE :q OR object_id ILIKE :q)';
            $parameters['q'] = '%' . $this->escapeLike($filters['q']) . '%';
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $parameters,
        ];
    }

    /** @return list<string> */
    private function distinctStrings(string $column): array
    {
        $allowed = ['actor_username', 'action', 'object_type'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }
        $statement = $this->pdo->query(
            'SELECT DISTINCT ' . $column . ' FROM audit_log ORDER BY ' . $column
        );
        return array_values(array_filter(
            array_map('strval', $statement?->fetchAll(PDO::FETCH_COLUMN) ?: []),
            static fn (string $value): bool => $value !== ''
        ));
    }

    private function identifier(mixed $value, int $maximum): ?string
    {
        $value = $this->boundedString($value, $maximum);
        if ($value === null || preg_match('/^[a-z0-9_.-]+$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    private function boundedString(mixed $value, int $maximum): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, $maximum);
    }

    private function dateBoundary(mixed $value, bool $exclusiveEnd): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }
        if ($exclusiveEnd) {
            $date = $date->modify('+1 day');
        }
        return $date->format('Y-m-d 00:00:00P');
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
