<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\ReliabilityReportRepository;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ReliabilityReportController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ReliabilityReportRepository $reports,
        private readonly Translator $translator,
    ) {
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string,string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $days = (string) ($request->getQueryParams()['period'] ?? '7') === '30' ? 30 : 7;
        $to = new DateTimeImmutable();
        $from = $to->modify('-' . $days . ' days');
        $report = $this->reports->report($from, $to);
        foreach (['servers', 'websites'] as $type) {
            foreach ($report[$type] as &$row) {
                $row['incident_duration_text'] = $this->duration((int) $row['incidents']['duration_seconds']);
                $row['recovery_text'] = $row['incidents']['mean_recovery_seconds'] === null
                    ? null : $this->duration((int) $row['incidents']['mean_recovery_seconds']);
                if ($type === 'servers') {
                    $row['downtime_text'] = $this->duration((int) $row['downtime_seconds']);
                }
            }
            unset($row);
        }

        return $this->twig->render($response, 'reports/reliability.twig', [
            'title' => $this->translator->trans('reliability.title'),
            'period' => $days,
            'from' => $from,
            'to' => $to,
            'report' => $report,
        ]);
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' ' . $this->translator->trans('common.seconds');
        }
        if ($seconds < 3600) {
            return round($seconds / 60, 1) . ' ' . $this->translator->trans('common.minutes');
        }
        return round($seconds / 3600, 1) . ' ' . $this->translator->trans('common.hours');
    }
}
