<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Domain\Websites\WebsiteEndpointDefinition;
use App\Repositories\WebsiteRepository;
use App\Security\SecretCipher;
use App\Services\WebsiteEndpointValidator;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class WebsiteRepositoryTest extends TestCase
{
    private static ?WebsiteCountingPdo $pdo = null;
    private WebsiteRepository $repository;
    private int $groupId;

    public static function setUpBeforeClass(): void
    {
        if (getenv('TEST_DB_HOST') === false) {
            self::markTestSkipped('Set TEST_DB_* to run the TimescaleDB integration suite.');
        }

        $environment = [
            'DB_HOST' => (string) getenv('TEST_DB_HOST'),
            'DB_PORT' => (string) (getenv('TEST_DB_PORT') ?: '5432'),
            'DB_NAME' => (string) getenv('TEST_DB_NAME'),
            'DB_USERNAME' => (string) getenv('TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('TEST_DB_PASSWORD'),
            'DB_SSLMODE' => (string) (getenv('TEST_DB_SSLMODE') ?: 'disable'),
        ];
        self::$pdo = new WebsiteCountingPdo(
            ConnectionFactory::dsn($environment),
            $environment['DB_USERNAME'],
            $environment['DB_PASSWORD'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );
        (new Migrator(self::pdo(), dirname(__DIR__, 3) . '/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        self::pdo()->beginTransaction();
        $this->groupId = (int) self::pdo()->query(
            "INSERT INTO monitoring_groups (name) VALUES ('Web') RETURNING id"
        )->fetchColumn();
        $this->repository = new WebsiteRepository(
            self::pdo(),
            new SecretCipher(str_repeat('w', 32))
        );
    }

    protected function tearDown(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    public function testCreatesSiteWithOnePrimaryAndEncryptedSecrets(): void
    {
        $siteId = $this->repository->create([
            'name' => 'Portal',
            'group_id' => $this->groupId,
            'registration_domain' => 'example.com',
            'domain_check_enabled' => true,
        ], [$this->endpoint([
            'auth_type' => 'bearer',
            'auth_secret' => 'bearer-secret',
            'headers' => [['name' => 'X-Api-Key', 'value' => 'header-secret']],
        ])]);

        self::assertSame(1, $this->countPrimary($siteId));
        $stored = $this->rawEndpoint($siteId);
        self::assertStringNotContainsString('bearer-secret', $stored['auth_encrypted']);
        self::assertStringNotContainsString('header-secret', $stored['headers_encrypted']);

        $public = json_encode([
            $this->repository->find($siteId),
            $this->repository->detail($siteId),
            $this->repository->groupedList([]),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('bearer-secret', $public);
        self::assertStringNotContainsString('header-secret', $public);
        self::assertStringNotContainsString('auth_encrypted', $public);
        self::assertStringNotContainsString('headers_encrypted', $public);

        $worker = $this->repository->endpointForCheck((int) $stored['id']);
        self::assertSame('bearer-secret', $worker['auth_secret'] ?? null);
        self::assertSame(['X-Api-Key' => 'header-secret'], $worker['headers'] ?? null);
    }

    public function testUpdateKeepsOmittedSecretAndCanClearAuthentication(): void
    {
        $siteId = $this->repository->create(
            ['name' => 'Portal', 'group_id' => $this->groupId],
            [$this->endpoint([
                'auth_type' => 'bearer',
                'auth_secret' => 'keep-me',
                'headers' => [['name' => 'X-Api-Key', 'value' => 'keep-header']],
            ])]
        );
        $endpointId = (int) $this->rawEndpoint($siteId)['id'];

        $keep = $this->endpoint([
            'id' => $endpointId,
            'is_primary' => true,
            'name' => 'Renamed',
            'auth_type' => 'bearer',
        ]);
        $this->repository->update(
            $siteId,
            ['name' => 'Portal renamed', 'group_id' => $this->groupId],
            [$keep]
        );
        self::assertSame(
            'keep-me',
            $this->repository->endpointForCheck($endpointId)['auth_secret'] ?? null
        );
        self::assertSame(
            ['X-Api-Key' => 'keep-header'],
            $this->repository->endpointForCheck($endpointId)['headers'] ?? null
        );

        $clear = $this->endpoint([
            'id' => $endpointId,
            'is_primary' => true,
            'auth_type' => 'none',
            'clear_headers' => true,
        ]);
        $this->repository->update(
            $siteId,
            ['name' => 'Portal renamed', 'group_id' => $this->groupId],
            [$clear]
        );
        self::assertNull($this->repository->endpointForCheck($endpointId)['auth_secret'] ?? null);
        self::assertSame([], $this->repository->endpointForCheck($endpointId)['headers'] ?? null);
        self::assertNull($this->rawEndpoint($siteId)['auth_encrypted']);
    }

    public function testUpdateRejectsNoEndpointsWithoutChangingCurrentSite(): void
    {
        $siteId = $this->repository->create(
            ['name' => 'Portal', 'group_id' => $this->groupId],
            [$this->endpoint()]
        );

        try {
            $this->repository->update($siteId, ['name' => 'Broken'], []);
            self::fail('Update without a primary endpoint must fail.');
        } catch (InvalidArgumentException) {
            self::assertSame('Portal', $this->repository->find($siteId)['name'] ?? null);
            self::assertSame(1, $this->countPrimary($siteId));
        }
    }

    public function testUpdateRejectsEndpointOwnedByAnotherWebsite(): void
    {
        $first = $this->repository->create(
            ['name' => 'First', 'group_id' => $this->groupId],
            [$this->endpoint()]
        );
        $second = $this->repository->create(
            ['name' => 'Second', 'group_id' => $this->groupId],
            [$this->endpoint(['name' => 'Second endpoint'])]
        );
        $foreignEndpointId = (int) $this->rawEndpoint($second)['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->repository->update($first, ['name' => 'First'], [
            $this->endpoint(['id' => $foreignEndpointId, 'is_primary' => true]),
        ]);
    }

    public function testDomainIsRequiredOnlyWhenMonitoringIsEnabled(): void
    {
        $withoutDomain = $this->repository->create(
            ['name' => 'Internal', 'domain_check_enabled' => false],
            [$this->endpoint(['url' => 'http://intranet/'])]
        );
        self::assertNull($this->repository->find($withoutDomain)['registration_domain'] ?? null);

        $this->expectException(InvalidArgumentException::class);
        $this->repository->create(
            ['name' => 'Invalid', 'domain_check_enabled' => true],
            [$this->endpoint()]
        );
    }

    public function testPauseAndResumeResetStateAndSchedulingWithoutRecoveryEvents(): void
    {
        $siteId = $this->repository->create(
            ['name' => 'Portal', 'group_id' => $this->groupId],
            [$this->endpoint()]
        );
        $endpointId = (int) $this->rawEndpoint($siteId)['id'];
        self::pdo()->exec(sprintf(
            "UPDATE website_endpoint_state
             SET transport_state = 'problem', transport_failures = 3
             WHERE endpoint_id = %d",
            $endpointId
        ));

        $this->repository->pause($siteId, new DateTimeImmutable('2026-08-27T10:00:00Z'));
        self::assertSame('paused', $this->endpointState($endpointId)['transport_state']);
        self::assertSame('paused', $this->siteState($siteId)['status']);

        $this->repository->resume($siteId, new DateTimeImmutable('2026-08-27T11:00:00Z'));
        self::assertSame('no_data', $this->endpointState($endpointId)['transport_state']);
        self::assertSame(0, (int) $this->endpointState($endpointId)['transport_failures']);
        self::assertSame('no_data', $this->siteState($siteId)['status']);
        self::assertSame(
            '0',
            (string) self::pdo()->query(
                'SELECT count(*) FROM website_availability_events WHERE website_id = ' . $siteId
            )->fetchColumn()
        );
    }

    public function testGroupsCanContainOnlySitesAndListQueryCountIsBounded(): void
    {
        for ($index = 1; $index <= 50; $index++) {
            $this->repository->create(
                ['name' => 'Site ' . $index, 'group_id' => $this->groupId],
                [$this->endpoint(['name' => 'Endpoint ' . $index])]
            );
        }

        self::pdo()->queryCount = 0;
        $groups = $this->repository->groupedList(['group_id' => $this->groupId]);

        self::assertSame(1, self::pdo()->queryCount);
        self::assertCount(1, $groups);
        self::assertSame('Web', $groups[0]['name']);
        self::assertCount(50, $groups[0]['websites']);
    }

    /** @param array<string, mixed> $overrides */
    private function endpoint(array $overrides = []): WebsiteEndpointDefinition
    {
        return (new WebsiteEndpointValidator())->validate(array_replace([
            'name' => 'Home',
            'url' => 'https://example.com/',
            'status_check_enabled' => true,
            'expected_statuses' => '200-299',
            'tls_expiry_enabled' => true,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function rawEndpoint(int $siteId): array
    {
        $statement = self::pdo()->prepare(
            "SELECT id, auth_encrypted, headers_encrypted,
                    encode(auth_encrypted, 'escape') AS auth_text,
                    encode(headers_encrypted, 'escape') AS headers_text
             FROM website_endpoints WHERE website_id = :website_id"
        );
        $statement->execute(['website_id' => $siteId]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        $row['auth_encrypted'] = $row['auth_text'];
        $row['headers_encrypted'] = $row['headers_text'];

        return $row;
    }

    private function countPrimary(int $siteId): int
    {
        $statement = self::pdo()->prepare(
            'SELECT count(*) FROM website_endpoints
             WHERE website_id = :website_id AND is_primary = TRUE'
        );
        $statement->execute(['website_id' => $siteId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function endpointState(int $endpointId): array
    {
        $statement = self::pdo()->prepare(
            'SELECT * FROM website_endpoint_state WHERE endpoint_id = :endpoint_id'
        );
        $statement->execute(['endpoint_id' => $endpointId]);

        return $statement->fetch() ?: [];
    }

    /** @return array<string, mixed> */
    private function siteState(int $siteId): array
    {
        $statement = self::pdo()->prepare(
            'SELECT * FROM website_state WHERE website_id = :website_id'
        );
        $statement->execute(['website_id' => $siteId]);

        return $statement->fetch() ?: [];
    }

    private static function pdo(): WebsiteCountingPdo
    {
        return self::$pdo ?? throw new \LogicException('Integration database is not connected.');
    }
}

final class WebsiteCountingPdo extends PDO
{
    public int $queryCount = 0;

    public function query(
        string $query,
        ?int $fetchMode = null,
        mixed ...$fetchModeArgs
    ): PDOStatement|false {
        $this->queryCount++;

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queryCount++;

        return parent::prepare($query, $options);
    }
}
