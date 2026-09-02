#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Backup\BackupContainer;
use App\Backup\BackupManifest;
use App\Backup\BackupPreflight;
use App\Backup\BackupSecretCatalog;
use App\Backup\DisasterRecoveryRestorer;
use App\Backup\DrCutoverJournal;
use App\Backup\DrMaintenanceLock;
use App\Backup\FullBackupCreator;
use App\Backup\PostgresBackupTool;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Security\SecretCipher;
use App\Services\AgentCredentialIssuer;

$appRoot = getenv('DR_ACCEPTANCE_APP_ROOT') ?: dirname(__DIR__, 2);
require $appRoot . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/mirvmon-dr-acceptance-' . bin2hex(random_bytes(6));
$prefix = 'mirvmon_dr_' . bin2hex(random_bytes(4));
$dbA = $prefix . '_a';
$dbB = $prefix . '_b';
$keyABytes = random_bytes(32);
$keyBBytes = random_bytes(32);
$keyA = base64_encode($keyABytes);
$keyB = base64_encode($keyBBytes);
$password = 'acceptance-' . bin2hex(random_bytes(16));
$operationId = bin2hex(random_bytes(16));
$serverProcess = null;
$serverPipes = [];
$serverLog = $root . '/http-server.log';
$selector = $root . '/backend';
$exitCode = 0;

$dbHost = requiredEnvironment('DR_ACCEPTANCE_DB_HOST');
$dbPort = getenv('DR_ACCEPTANCE_DB_PORT') ?: '5432';
$dbUser = requiredEnvironment('DR_ACCEPTANCE_DB_USERNAME');
$dbPassword = requiredEnvironment('DR_ACCEPTANCE_DB_PASSWORD');
$agentBinary = getenv('DR_ACCEPTANCE_AGENT') ?: '/app/agent-dist/mirvmon-agent-linux-amd64';
$baseUrl = getenv('DR_ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18080';
$migrations = $appRoot . '/migrations';
$admin = null;

try {
    mkdir($root, 0700, true);
    chmod($root, 0700);
    assertTrue($keyA !== $keyB, 'Acceptance keys A and B must differ.');
    assertTrue(is_file($agentBinary) && is_executable($agentBinary), 'Real native agent binary is missing.');

    $admin = connectDatabase('postgres', $dbHost, $dbPort, $dbUser, $dbPassword);
    createDatabase($admin, $dbA);
    createDatabase($admin, $dbB);

    $pdoA = connectDatabase($dbA, $dbHost, $dbPort, $dbUser, $dbPassword);
    $pdoB = connectDatabase($dbB, $dbHost, $dbPort, $dbUser, $dbPassword);
    (new Migrator($pdoA, $migrations))->migrate();
    (new Migrator($pdoB, $migrations))->migrate();

    $pdoA->prepare("INSERT INTO users (username, password_hash, role) VALUES ('source-admin', :hash, 'admin')")
        ->execute(['hash' => password_hash('source-password', PASSWORD_DEFAULT)]);
    $pdoB->prepare("INSERT INTO users (username, password_hash, role) VALUES ('temporary-b-admin', :hash, 'admin')")
        ->execute(['hash' => password_hash('temporary-password', PASSWORD_DEFAULT)]);

    $serverId = (int) $pdoA->query("INSERT INTO servers (name, address) VALUES ('dr-agent', '127.0.0.1') RETURNING id")
        ->fetchColumn();
    $issuerA = new AgentCredentialIssuer($pdoA, $keyABytes);
    $installer = $issuerA->issueInstaller($serverId);
    $credential = $issuerA->exchange($installer);
    $unusedInstaller = $issuerA->issueInstaller($serverId);
    assertTrue(strlen($unusedInstaller) === 64, 'Unused installer token was not created.');

    $cipherA = new SecretCipher($keyABytes);
    $notification = $pdoA->prepare(
        'UPDATE notification_settings SET smtp_password_encrypted = :smtp, telegram_bot_token_encrypted = :telegram WHERE id = 1'
    );
    $notification->execute([
        'smtp' => $cipherA->encrypt('smtp-dr-secret'),
        'telegram' => $cipherA->encrypt('telegram-dr-secret'),
    ]);
    $websiteId = (int) $pdoA->query("INSERT INTO websites (name) VALUES ('dr.example') RETURNING id")->fetchColumn();
    $endpoint = $pdoA->prepare(
        "INSERT INTO website_endpoints (website_id, name, url, is_primary, auth_type, auth_encrypted, headers_encrypted)
         VALUES (:website_id, 'primary', 'https://dr.example/', TRUE, 'basic', :auth, :headers) RETURNING id"
    );
    $endpoint->execute([
        'website_id' => $websiteId,
        'auth' => $cipherA->encrypt('{"type":"basic","username":"dr","secret":"website-secret"}'),
        'headers' => $cipherA->encrypt('{"X-DR":"secret-header"}'),
    ]);
    $endpointId = (int) $endpoint->fetchColumn();
    $websiteSample = $pdoA->prepare(
        "INSERT INTO website_check_samples (
            sample_time, website_id, endpoint_id, sample_id, transport_available, assertions_passed,
            status_code, configured_url, final_url, total_ms
         ) VALUES (
            CURRENT_TIMESTAMP - INTERVAL '5 minutes', :website_id, :endpoint_id,
            '40000000-0000-4000-8000-000000000001', TRUE, TRUE, 200,
            'https://dr.example/', 'https://dr.example/', 12.5
         )"
    );
    $websiteSample->execute(['website_id' => $websiteId, 'endpoint_id' => $endpointId]);

    file_put_contents($selector, "a\n");
    $serverProcess = startHttpServer($serverLog, [
        'DR_ACCEPTANCE_SELECTOR' => $selector,
        'DR_ACCEPTANCE_DB_A' => $dbA,
        'DR_ACCEPTANCE_DB_B' => $dbB,
        'DR_ACCEPTANCE_KEY_A' => $keyA,
        'DR_ACCEPTANCE_KEY_B' => $keyB,
        'DR_ACCEPTANCE_DB_HOST' => $dbHost,
        'DR_ACCEPTANCE_DB_PORT' => $dbPort,
        'DR_ACCEPTANCE_DB_USERNAME' => $dbUser,
        'DR_ACCEPTANCE_DB_PASSWORD' => $dbPassword,
        'DR_ACCEPTANCE_ROOT' => $root,
        'DR_ACCEPTANCE_BASE_URL' => $baseUrl,
    ], $serverPipes);
    waitForHttp($baseUrl . '/livez');

    $agentConfig = $root . '/agent.json';
    $agentQueue = $root . '/agent-queue.json';
    writeJson($agentConfig, [
        'api_url' => $baseUrl . '/api/v1/metrics',
        'config_url' => $baseUrl . '/api/v1/agent/config',
        'token' => $credential->token,
        'queue_path' => $agentQueue,
        'interval_seconds' => 60,
        'verify_tls' => false,
        'collect_process_commands' => false,
        'enabled' => true,
        'monitor_services' => [],
        'queue_limit' => 100,
    ]);
    $configDigest = hash_file('sha256', $agentConfig);
    assertTrue(is_string($configDigest), 'Cannot hash agent config.');

    echo "[dr-acceptance] real agent -> A\n";
    runAgent($agentBinary, $agentConfig);
    $sourceSamples = scalarInt($pdoA, 'SELECT count(*) FROM ingested_samples WHERE server_id = ' . $serverId);
    $sourceMetricRows = scalarInt($pdoA, 'SELECT count(*) FROM metric_samples WHERE server_id = ' . $serverId);
    assertSame(1, $sourceSamples, 'A must contain exactly one real-agent sample before backup.');
    assertTrue($sourceMetricRows > 0, 'A must contain real metric history before backup.');
    assertSame(1, scalarInt($pdoA, 'SELECT count(*) FROM website_check_samples WHERE website_id = ' . $websiteId), 'A website history is missing.');
    $sourceToken = tokenRow($pdoA, $serverId);

    $envA = databaseEnvironment($dbA, $dbHost, $dbPort, $dbUser, $dbPassword);
    $backupPath = $root . '/full.mmbak';
    echo "[dr-acceptance] create full encrypted backup from A\n";
    $creator = new FullBackupCreator(
        $pdoA,
        new BackupSecretCatalog($pdoA, $cipherA),
        new BackupManifest($pdoA, $migrations, 'acceptance-a', 'acceptance-a-commit'),
        new PostgresBackupTool($envA),
        new BackupContainer(),
        $root . '/backup-work'
    );
    $manifest = $creator->create($backupPath, $password);
    assertTrue(is_file($backupPath) && filesize($backupPath) > 0, 'Encrypted full backup was not created.');

    $envB = databaseEnvironment($dbB, $dbHost, $dbPort, $dbUser, $dbPassword);
    $preflight = new BackupPreflight(
        $pdoB,
        new BackupContainer(),
        new BackupManifest($pdoB, $migrations, 'acceptance-b', 'acceptance-b-commit'),
        new BackupSecretCatalog($pdoB, new SecretCipher($keyBBytes)),
        new PostgresBackupTool($envB)
    );

    echo "[dr-acceptance] wrong password must not modify B\n";
    try {
        $preflight->run($backupPath, 'definitely-wrong-password', $root . '/wrong-preflight');
        throw new RuntimeException('Wrong backup password unexpectedly passed preflight.');
    } catch (Throwable $exception) {
        if ($exception->getMessage() === 'Wrong backup password unexpectedly passed preflight.') {
            throw $exception;
        }
    }
    assertSame(1, scalarInt($pdoB, "SELECT count(*) FROM users WHERE username = 'temporary-b-admin'"), 'Wrong-password preflight modified B.');
    assertSame(0, scalarInt($pdoB, "SELECT count(*) FROM users WHERE username = 'source-admin'"), 'Wrong-password preflight leaked A into B.');

    echo "[dr-acceptance] corrupted backup must not modify B\n";
    $corruptBackup = $root . '/corrupt.mmbak';
    corruptCopy($backupPath, $corruptBackup);
    try {
        $preflight->run($corruptBackup, $password, $root . '/corrupt-preflight');
        throw new RuntimeException('Corrupted backup unexpectedly passed preflight.');
    } catch (Throwable $exception) {
        if ($exception->getMessage() === 'Corrupted backup unexpectedly passed preflight.') {
            throw $exception;
        }
    }
    assertSame(1, scalarInt($pdoB, "SELECT count(*) FROM users WHERE username = 'temporary-b-admin'"), 'Corrupt-backup preflight modified B.');
    assertSame(0, scalarInt($pdoB, "SELECT count(*) FROM users WHERE username = 'source-admin'"), 'Corrupt-backup preflight leaked A into B.');

    echo "[dr-acceptance] preflight + restore into fresh B with APP_KEY=B\n";
    $checked = $preflight->run($backupPath, $password, $root . '/preflight');
    assertSame($manifest['backup_id'], $checked['manifest']['backup_id'], 'Preflight manifest changed backup identity.');

    // The restorer deliberately terminates all B connections during cutover.
    $pdoB = null;
    $runtimeRoot = $root . '/runtime';
    $sessionDirectory = $root . '/sessions';
    mkdir($sessionDirectory, 0700, true);
    file_put_contents($sessionDirectory . '/sess_temporary', 'temporary-session');
    $restorer = new DisasterRecoveryRestorer(
        $envB,
        new PostgresBackupTool($envB),
        new DrMaintenanceLock($runtimeRoot),
        new DrCutoverJournal($runtimeRoot),
        $migrations,
        $keyBBytes,
        $sessionDirectory
    );
    $result = $restorer->restore($operationId, $checked['workspace'], $checked['manifest']);
    $restorer->acknowledgeCompletedCutover($operationId);
    assertSame($dbB, $result['database'], 'Restore did not cut over to B database name.');
    assertTrue(!is_file($sessionDirectory . '/sess_temporary'), 'Temporary B session survived restore.');

    file_put_contents($selector, "b\n");
    $pdoB = connectDatabase($dbB, $dbHost, $dbPort, $dbUser, $dbPassword);
    assertSame(0, scalarInt($pdoB, "SELECT count(*) FROM users WHERE username = 'temporary-b-admin'"), 'Temporary B administrator survived restore.');
    assertSame(1, scalarInt($pdoB, "SELECT count(*) FROM users WHERE username = 'source-admin'"), 'Source A administrator was not restored.');
    assertSame(1, scalarInt($pdoB, 'SELECT count(*) FROM ingested_samples WHERE server_id = ' . $serverId), 'A agent history was not restored into B.');
    assertSame($sourceMetricRows, scalarInt($pdoB, 'SELECT count(*) FROM metric_samples WHERE server_id = ' . $serverId), 'A metric history changed during restore.');
    assertSame(1, scalarInt($pdoB, 'SELECT count(*) FROM website_check_samples WHERE website_id = ' . $websiteId), 'Website history was not restored.');
    assertSame($sourceToken, tokenRow($pdoB, $serverId), 'Permanent agent token hash/generation changed during restore.');
    assertSame(0, scalarInt($pdoB, 'SELECT count(*) FROM installer_tokens WHERE consumed_at IS NULL'), 'Unused installer tokens were resurrected after restore.');

    $cipherB = new SecretCipher($keyBBytes);
    $restoredNotification = $pdoB->query('SELECT smtp_password_encrypted, telegram_bot_token_encrypted FROM notification_settings WHERE id = 1')->fetch();
    assertTrue(is_array($restoredNotification), 'Restored notification settings are missing.');
    assertSame('smtp-dr-secret', $cipherB->decrypt(databaseBytes($restoredNotification['smtp_password_encrypted'])), 'SMTP secret was not re-encrypted under B.');
    assertSame('telegram-dr-secret', $cipherB->decrypt(databaseBytes($restoredNotification['telegram_bot_token_encrypted'])), 'Telegram secret was not re-encrypted under B.');
    $restoredEndpoint = $pdoB->query('SELECT auth_encrypted, headers_encrypted FROM website_endpoints WHERE id = ' . $endpointId)->fetch();
    assertTrue(is_array($restoredEndpoint), 'Restored website endpoint is missing.');
    assertSame('{"type":"basic","username":"dr","secret":"website-secret"}', $cipherB->decrypt(databaseBytes($restoredEndpoint['auth_encrypted'])), 'Website auth secret was not re-encrypted under B.');
    assertSame('{"X-DR":"secret-header"}', $cipherB->decrypt(databaseBytes($restoredEndpoint['headers_encrypted'])), 'Website header secret was not re-encrypted under B.');

    $issuerB = new AgentCredentialIssuer($pdoB, $keyBBytes);
    assertTrue(!$issuerB->canIssueInstaller($serverId), 'B must require explicit credential rotation before issuing a new installer.');
    assertSame($configDigest, hash_file('sha256', $agentConfig), 'Agent config changed during A -> B restore.');

    echo "[dr-acceptance] SAME real agent config/token -> restored B\n";
    runAgent($agentBinary, $agentConfig);
    assertSame(2, scalarInt($pdoB, 'SELECT count(*) FROM ingested_samples WHERE server_id = ' . $serverId), 'Existing agent did not deliver a new sample to restored B.');
    assertTrue(scalarInt($pdoB, 'SELECT count(*) FROM metric_samples WHERE server_id = ' . $serverId) > $sourceMetricRows, 'Restored B did not append new metric history from the unchanged agent.');
    assertSame($sourceToken, tokenRow($pdoB, $serverId), 'Agent credential changed after post-restore delivery.');
    assertSame($configDigest, hash_file('sha256', $agentConfig), 'Agent config was modified after post-restore delivery.');

    echo "[dr-acceptance] maintenance 503 must retain and later flush the real agent queue\n";
    file_put_contents($root . '/fail-next-metrics', "1\n", LOCK_EX);
    $pending = executeAgent($agentBinary, $agentConfig);
    assertSame(3, $pending['code'], 'Agent must report delivery pending when maintenance returns 503.');
    assertTrue(str_contains($pending['stderr'], 'delivery pending'), 'Agent did not classify maintenance response as retryable delivery pending.');
    assertSame(1, queueLength($agentQueue), 'Agent did not retain the metrics envelope after maintenance 503.');
    assertSame(2, scalarInt($pdoB, 'SELECT count(*) FROM ingested_samples WHERE server_id = ' . $serverId), 'Maintenance 503 unexpectedly ingested a sample.');
    @unlink($root . '/http-dr/maintenance.json');
    runAgent($agentBinary, $agentConfig);
    assertSame(0, queueLength($agentQueue), 'Agent queue did not drain after maintenance ended.');
    assertSame(3, scalarInt($pdoB, 'SELECT count(*) FROM ingested_samples WHERE server_id = ' . $serverId), 'Queued sample was not accepted after maintenance ended.');

    echo "[dr-acceptance] PASS: A(APP_KEY=A) -> backup -> B(APP_KEY=B) -> unchanged real agent -> metrics accepted\n";
} catch (Throwable $exception) {
    $exitCode = 1;
    fwrite(STDERR, "[dr-acceptance] FAIL: {$exception->getMessage()}\n");
    if (is_file($serverLog)) {
        fwrite(STDERR, "--- acceptance HTTP server log ---\n" . (string) file_get_contents($serverLog) . "\n--- end log ---\n");
    }
} finally {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess, SIGTERM);
        foreach ($serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($serverProcess);
    }
    if ($admin instanceof PDO) {
        cleanupDatabases($admin, $prefix);
    }
    removeTree($root);
}

exit($exitCode);

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException($name . ' is required.');
    }
    return $value;
}

/** @return array<string,string> */
function databaseEnvironment(string $database, string $host, string $port, string $username, string $password): array
{
    return [
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $database,
        'DB_USERNAME' => $username,
        'DB_PASSWORD' => $password,
        'DB_SSLMODE' => 'disable',
    ];
}

function connectDatabase(string $database, string $host, string $port, string $username, string $password): PDO
{
    return ConnectionFactory::connect(databaseEnvironment($database, $host, $port, $username, $password));
}

function createDatabase(PDO $admin, string $database): void
{
    $admin->exec('CREATE DATABASE ' . quoteIdentifier($database) . ' TEMPLATE template0');
}

function cleanupDatabases(PDO $admin, string $prefix): void
{
    $statement = $admin->prepare("SELECT datname FROM pg_database WHERE datname LIKE :pattern ORDER BY datname DESC");
    $statement->execute(['pattern' => $prefix . '%']);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $database) {
        if (!is_string($database)) {
            continue;
        }
        try {
            $terminate = $admin->prepare("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()");
            $terminate->execute(['database' => $database]);
            $terminate->fetchAll();
            $admin->exec('DROP DATABASE IF EXISTS ' . quoteIdentifier($database) . ' WITH (FORCE)');
        } catch (Throwable $exception) {
            fwrite(STDERR, "[dr-acceptance] cleanup warning for {$database}: {$exception->getMessage()}\n");
        }
    }
}

function quoteIdentifier(string $identifier): string
{
    if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $identifier) !== 1) {
        throw new RuntimeException('Unsafe acceptance database identifier.');
    }
    return '"' . $identifier . '"';
}

/** @param array<string,string> $extraEnvironment @param array<int,resource> $pipes */
function startHttpServer(string $log, array $extraEnvironment, array &$pipes): mixed
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $process = proc_open(
        ['php', '-S', '127.0.0.1:18080', __DIR__ . '/dr-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $extraEnvironment['DR_ACCEPTANCE_APP_ROOT'] ?? dirname(__DIR__, 2),
        array_merge($environment, $extraEnvironment),
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start acceptance HTTP server.');
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
        unset($pipes[0]);
    }
    return $process;
}

function waitForHttp(string $url): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize HTTP readiness check.');
        }
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT_MS => 500]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status === 200 && $body === 'alive') {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('Acceptance HTTP endpoint did not become ready.');
}

/** @return array{code:int,stdout:string,stderr:string} */
function executeAgent(string $binary, string $config): array
{
    $process = proc_open(
        [$binary, 'once', '--require-delivery', '--config', $config],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start real MirvMon agent.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [
        'code' => $code,
        'stdout' => is_string($stdout) ? trim($stdout) : '',
        'stderr' => is_string($stderr) ? trim($stderr) : '',
    ];
}

function runAgent(string $binary, string $config): void
{
    $result = executeAgent($binary, $config);
    if ($result['code'] !== 0) {
        throw new RuntimeException(sprintf(
            'Real agent failed with exit %d: %s %s',
            $result['code'],
            $result['stdout'],
            $result['stderr']
        ));
    }
}

function queueLength(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $items = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($items) || !array_is_list($items)) {
        throw new RuntimeException('Agent queue has invalid acceptance-test shape.');
    }
    return count($items);
}

function corruptCopy(string $source, string $destination): void
{
    if (!copy($source, $destination)) {
        throw new RuntimeException('Cannot create corrupted backup fixture.');
    }
    $handle = fopen($destination, 'r+b');
    if ($handle === false || fseek($handle, -1, SEEK_END) !== 0) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Cannot seek corrupted backup fixture.');
    }
    $byte = fread($handle, 1);
    if (!is_string($byte) || strlen($byte) !== 1) {
        fclose($handle);
        throw new RuntimeException('Cannot read corrupted backup fixture byte.');
    }
    fseek($handle, -1, SEEK_END);
    fwrite($handle, chr(ord($byte) ^ 0x01));
    fclose($handle);
}

/** @param array<string,mixed> $payload */
function writeJson(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('Cannot write acceptance JSON file.');
    }
    chmod($path, 0600);
}

function scalarInt(PDO $pdo, string $sql): int
{
    return (int) ($pdo->query($sql)->fetchColumn() ?: 0);
}

/** @return array{token_hash:string,token_generation:int} */
function tokenRow(PDO $pdo, int $serverId): array
{
    $row = $pdo->query('SELECT token_hash, token_generation FROM agent_tokens WHERE server_id = ' . $serverId)->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('Permanent agent token row is missing.');
    }
    return ['token_hash' => (string) $row['token_hash'], 'token_generation' => (int) $row['token_generation']];
}

function databaseBytes(mixed $value): string
{
    if (is_resource($value)) {
        $contents = stream_get_contents($value);
        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read database bytea stream.');
        }
        return $contents;
    }
    if (!is_string($value)) {
        throw new RuntimeException('Database bytea is not a string.');
    }
    if (str_starts_with($value, '\\x')) {
        $decoded = hex2bin(substr($value, 2));
        if (!is_string($decoded)) {
            throw new RuntimeException('Cannot decode database bytea hex value.');
        }
        return $decoded;
    }
    return $value;
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            removeTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}
