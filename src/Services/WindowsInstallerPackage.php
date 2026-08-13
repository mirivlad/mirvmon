<?php

declare(strict_types=1);

namespace App\Services;

final readonly class WindowsInstallerPackage
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $contents
    ) {
    }
}
