<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerPlatformService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerPlatformServiceTest extends TestCase
{
    #[DataProvider('platformCases')]
    public function testClassifiesAgentArtifactBeforeReportedSystem(
        ?string $os,
        ?string $artifact,
        string $family,
        string $icon
    ): void {
        $platform = (new ServerPlatformService())->classify($os, $artifact);

        self::assertSame($family, $platform['family']);
        self::assertSame($icon, $platform['icon_class']);
        self::assertNotSame('', $platform['tooltip']);
    }

    /** @return iterable<string, array{?string, ?string, string, string}> */
    public static function platformCases(): iterable
    {
        yield 'artifact wins over stale OS' => [
            'Debian GNU/Linux 12', 'windows-amd64', 'windows', 'fab fa-windows',
        ];
        yield 'modern windows' => [
            'Windows Server 2022', null, 'windows', 'fab fa-windows',
        ];
        yield 'debian' => ['Debian GNU/Linux 12', null, 'linux', 'fab fa-linux'];
        yield 'ubuntu' => ['Ubuntu 24.04 LTS', null, 'linux', 'fab fa-linux'];
        yield 'centos' => ['CentOS Linux 7', null, 'linux', 'fab fa-linux'];
        yield 'nethserver' => ['NethServer 7.9', null, 'linux', 'fab fa-linux'];
        yield 'oracle' => ['Oracle Linux Server 9', null, 'linux', 'fab fa-linux'];
        yield 'rhel' => ['Red Hat Enterprise Linux 9', null, 'linux', 'fab fa-linux'];
        yield 'rocky' => ['Rocky Linux 9', null, 'linux', 'fab fa-linux'];
        yield 'alma' => ['AlmaLinux 9', null, 'linux', 'fab fa-linux'];
        yield 'generic linux' => ['Linux 6.8', null, 'linux', 'fab fa-linux'];
        yield 'missing' => [null, null, 'unknown', 'fas fa-server'];
        yield 'unrecognised' => ['FreeBSD 14', null, 'unknown', 'fas fa-server'];
    }
}
