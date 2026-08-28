<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ComposeContractTest extends TestCase
{
    private string $compose;
    private string $dockerfile;
    private string $caddyfile;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->compose = (string) file_get_contents($root . '/docker/docker-compose.yml');
        $this->dockerfile = (string) file_get_contents($root . '/docker/Dockerfile');
        $this->caddyfile = (string) file_get_contents($root . '/docker/Caddyfile');
    }

    public function testProductionStackContainsExactlyAppAndDatabase(): void
    {
        preg_match('/^services:\R(?<services>.*?)(?=^volumes:)/ms', $this->compose, $match);
        self::assertArrayHasKey('services', $match);

        preg_match_all('/^  ([a-z][a-z0-9_-]*):\s*$/m', $match['services'], $names);
        self::assertSame(['app', 'db'], $names[1]);
    }

    public function testDatabaseIsPinnedPersistentHealthyAndNotPublished(): void
    {
        $database = $this->serviceBlock('db');

        self::assertStringContainsString(
            'timescale/timescaledb:2.28.3-pg17@sha256:9372c578c11ea83c56e4a8f7af6ec4156646722d270f5ade79559ccb101161a9',
            $database
        );
        self::assertStringContainsString('healthcheck:', $database);
        self::assertStringContainsString(
            'head -1 /var/lib/postgresql/data/postmaster.pid | grep -qx 1',
            $database
        );
        self::assertStringContainsString('pg_isready', $database);
        self::assertStringContainsString('db_data:/var/lib/postgresql/data', $database);
        self::assertStringNotContainsString("\n    ports:", $database);
    }

    public function testApplicationIsRootlessHealthyAndBoundToLoopbackByDefault(): void
    {
        $application = $this->serviceBlock('app');

        self::assertStringContainsString('ghcr.io/mirivlad/mirvmon:latest', $application);
        self::assertStringNotContainsString("\n    build:", $application);
        self::assertStringContainsString('127.0.0.1', $application);
        self::assertStringContainsString('healthcheck:', $application);
        self::assertStringContainsString('/readyz', $application);
        self::assertStringContainsString('WEBSITE_CHECK_LOOP_INTERVAL', $application);
        self::assertStringContainsString('host.docker.internal:host-gateway', $application);
        self::assertStringContainsString('read_only: true', $application);
        self::assertStringContainsString('no-new-privileges:true', $application);
        self::assertStringContainsString('SERVER_NAME: ":8080"', $application);
        self::assertStringContainsString(
            'SESSION_SECURE: "${SESSION_SECURE:-1}"',
            $application
        );
        self::assertStringContainsString('mem_limit:', $application);
        self::assertStringContainsString('cpus:', $application);
        self::assertStringContainsString('USER app', $this->dockerfile);
        // The FrankenPHP base image probes the Caddy admin API, which the
        // Caddyfile disables, so the image must declare its own check.
        self::assertMatchesRegularExpression(
            '/^HEALTHCHECK .*\R\s+CMD curl .*\/readyz/m',
            $this->dockerfile
        );
        self::assertStringContainsString('admin off', $this->caddyfile);
        self::assertStringContainsString(
            'SETUP_TOKEN: "${SETUP_TOKEN:?Set SETUP_TOKEN in Portainer or .env}"',
            $application
        );
    }

    public function testRuntimeImagesAreCurrentAndReproducible(): void
    {
        self::assertStringContainsString(
            'dunglas/frankenphp:1.12.7-php8.5-trixie@sha256:4b0713ddad6ca7eb21eb82ac6bdb7cb41de5192a930b615d89af6e15d74e82f8',
            $this->dockerfile
        );
        self::assertStringContainsString(
            'composer:2.10.1@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267',
            $this->dockerfile
        );
    }

    public function testNativeAgentArtifactsAreBuiltFromPinnedGoToolchains(): void
    {
        self::assertStringContainsString(
            'golang:1.26.5-bookworm@sha256:53eeac89074db483fdf0ab3be1df32bf6e47562263d2d0d6baa7f26acb4957dd',
            $this->dockerfile
        );
        self::assertStringContainsString(
            'golang:1.20.14-bookworm@sha256:9fa9101141c01e9440216d32eb2b380b3c3079bea07aeab3546020cc91b3662c',
            $this->dockerfile
        );
        self::assertStringContainsString('AS agent-modern', $this->dockerfile);
        self::assertStringContainsString('AS agent-legacy', $this->dockerfile);
        self::assertStringContainsString('GOAMD64=v1', $this->dockerfile);
        self::assertStringContainsString('manifest.json', $this->dockerfile);
        self::assertStringContainsString('COPY --from=agent-dist /out /app/agent-dist', $this->dockerfile);
    }

    public function testPersistentTwigCacheIsInvalidatedOnImageStart(): void
    {
        $entrypoint = (string) file_get_contents(
            dirname(__DIR__, 2) . '/docker/entrypoint.sh'
        );

        self::assertStringContainsString(
            'find "$twig_cache_directory" -mindepth 1 -delete',
            $entrypoint
        );
    }

    public function testSelfHostedBrowserAssetsReceiveExplicitCachePolicy(): void
    {
        self::assertStringContainsString('/vendor/*', $this->caddyfile);
        self::assertStringContainsString('/favicon.png', $this->caddyfile);
        self::assertStringContainsString(
            'Cache-Control "public, max-age=3600"',
            $this->caddyfile
        );
    }

    private function serviceBlock(string $service): string
    {
        preg_match(
            '/^  ' . preg_quote($service, '/') . ':\R(?<block>(?: {4}.*\R?)*)/m',
            $this->compose,
            $match
        );
        self::assertArrayHasKey('block', $match);

        return $match['block'];
    }
}
