<?php

declare(strict_types=1);

namespace App\Services;

final class ServerPlatformService
{
    /**
     * @return array{
     *     family: 'windows'|'linux'|'unknown',
     *     icon_class: string,
     *     tooltip: string
     * }
     */
    public function classify(?string $osVersion, ?string $artifact): array
    {
        $osVersion = trim((string) $osVersion);
        $artifact = strtolower(trim((string) $artifact));
        $reportedFamily = $this->familyFromText($osVersion);
        $family = match (true) {
            str_starts_with($artifact, 'windows-') => 'windows',
            str_starts_with($artifact, 'linux-') => 'linux',
            default => $reportedFamily,
        };
        $icon = match ($family) {
            'windows' => 'fab fa-windows',
            'linux' => 'fab fa-linux',
            default => 'fas fa-server',
        };
        if ($osVersion !== '' && ($reportedFamily === $family || $family === 'unknown')) {
            $tooltip = $osVersion;
        } else {
            $tooltip = match ($family) {
                'windows' => 'Windows · точная версия не сообщена',
                'linux' => 'Linux · точная версия не сообщена',
                default => 'ОС не сообщена',
            };
        }

        return [
            'family' => $family,
            'icon_class' => $icon,
            'tooltip' => $tooltip,
        ];
    }

    /** @return 'windows'|'linux'|'unknown' */
    private function familyFromText(string $value): string
    {
        $value = strtolower($value);
        if (str_contains($value, 'windows')) {
            return 'windows';
        }
        foreach ([
            'linux',
            'ubuntu',
            'debian',
            'centos',
            'nethserver',
            'red hat',
            'rhel',
            'oracle',
            'rocky',
            'almalinux',
        ] as $marker) {
            if (str_contains($value, $marker)) {
                return 'linux';
            }
        }

        return 'unknown';
    }
}
