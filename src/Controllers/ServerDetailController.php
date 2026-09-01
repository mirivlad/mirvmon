<?php

declare(strict_types=1);

namespace App\Controllers;

use App\I18n\Translator;
use App\I18n\TwigTranslation;
use App\Repositories\AvailabilityRepository;
use App\Repositories\IncidentRepository;
use App\Repositories\MaintenanceWindowRepository;
use App\Repositories\MetricRepository;
use App\Repositories\ServerRepository;
use App\Services\AgentUpdateService;
use App\Services\ServerMetricViewBuilder;
use App\Services\ServerStatusService;
use App\Services\ServerTimingService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Throwable;

final class ServerDetailController
{
    private const PERIODS = [
        '1h' => '-1 hour',
        '6h' => '-6 hours',
        '24h' => '-24 hours',
        '7d' => '-7 days',
        '30d' => '-30 days',
    ];

    private const TABS = ['overview', 'metrics', 'services', 'events', 'agent'];

    private readonly IncidentRepository $incidents;
    private readonly ServerMetricViewBuilder $metricView;
    private readonly ServerTimingService $timing;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Twig $twig,
        private readonly ServerRepository $servers,
        private readonly MetricRepository $metrics,
        private readonly MaintenanceWindowRepository $maintenance,
        private readonly ?AgentUpdateService $agentUpdates,
        private readonly ServerStatusService $status,
        private readonly Translator $translator = new Translator(),
        ?IncidentRepository $incidents = null
    ) {
        $this->incidents = $incidents ?? new IncidentRepository($this->pdo);
        $this->metricView = new ServerMetricViewBuilder($this->translator);
        $this->timing = new ServerTimingService();
        TwigTranslation::register($this->twig->getEnvironment(), $this->translator);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }

        $server = $this->servers->find($serverId);
        if ($server === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $server = $this->status->enrich([$server])[0];

        $agentToken = $this->pdo->prepare(
            'SELECT token_generation FROM agent_tokens WHERE server_id = :server_id'
        );
        $agentToken->execute(['server_id' => $serverId]);
        $tokenGeneration = $agentToken->fetchColumn();
        $agentUpdate = $this->agentUpdates?->statusForServer($serverId);

        $query = $request->getQueryParams();
        $activeTab = is_string($query['tab'] ?? null) && in_array($query['tab'], self::TABS, true)
            ? $query['tab']
            : 'overview';
        $period = is_string($query['period'] ?? null) && isset(self::PERIODS[$query['period']])
            ? $query['period']
            : '24h';
        $zoom = is_string($query['zoom'] ?? null) && isset(self::PERIODS[$query['zoom']])
            ? $query['zoom']
            : null;
        [$startDate, $endDate] = $this->metricRange($query, $period, $zoom);
        $totalMinutes = max(
            1,
            (int) ceil(($endDate->getTimestamp() - $startDate->getTimestamp()) / 60)
        );

        $displayMetrics = $server['display_metrics'] === [] ? null : $server['display_metrics'];
        $emptyMetricView = [
            'simple' => [],
            'network' => [],
            'disk_io' => [],
            'temperature' => ['datasets' => []],
            'disks' => [],
            'uptime' => [],
            'summary' => [],
        ];
        $metricView = $emptyMetricView;
        $series = [
            'source' => $this->metrics->sourceForRange($startDate, $endDate),
            'bucket_seconds' => 0,
            'points' => [],
        ];
        $availabilityChart = ['known' => false, 'labels' => [], 'timestamps' => [], 'values' => []];
        $summaryCards = [];

        if (in_array($activeTab, ['overview', 'metrics'], true)) {
            $queryMetricNames = $displayMetrics === null ? [] : $this->expandDisplayMetrics($displayMetrics);
            $series = $queryMetricNames === []
                ? $series
                : $this->metrics->series($serverId, $startDate, $endDate, $queryMetricNames);
            $grouped = $this->metricView->group($series['points']);
            $current = $queryMetricNames === []
                ? []
                : $this->metrics->latestValues($serverId, $queryMetricNames);
            $metricView = $this->metricView->build(
                $grouped,
                $displayMetrics,
                $this->servers->thresholds($serverId),
                $current
            );

            if ($this->metricSelected('availability', $displayMetrics)) {
                $availabilityChart = $this->metricView->availabilityChart(
                    (new AvailabilityRepository($this->pdo))->timeline(
                        $serverId,
                        $startDate,
                        $endDate
                    )
                );
            }
            $summaryCards = $metricView['summary'];
            if (($availabilityChart['known'] ?? false) === true) {
                $summaryCards[] = [
                    'title' => $this->translator->trans('metric.availability_period'),
                    'value' => $availabilityChart['availabilityPercent'] . '%',
                    'subtitle' => $this->translator->trans('metric.downtime_outages', [
                        'downtime' => $availabilityChart['downtimeText'],
                        'outages' => $availabilityChart['outages'],
                    ]),
                ];
            }
        }

        $activeIncidents = $this->incidents->active(['server_id' => $serverId]);
        $incidentHistory = $activeTab === 'events'
            ? $this->incidents->history(['server_id' => $serverId])
            : [];
        $latestUptime = $activeTab === 'overview' ? $this->metrics->latestUptime($serverId) : null;
        $allServices = $activeTab === 'services' ? $this->servers->services($serverId) : [];
        $monitorServices = $activeTab === 'services' ? $this->servers->monitoredServices($serverId) : [];

        return $this->twig->render($response, 'servers/detail.twig', [
            'title' => $this->translator->trans('server.page_title', ['name' => $server['name']]),
            'server' => $server,
            'active_tab' => $activeTab,
            'has_agent_token' => $tokenGeneration !== false,
            'requires_token_rotation' => $tokenGeneration === null,
            'agent_update' => $agentUpdate,
            'host_architecture' => $this->architecture((string) ($server['agent_artifact'] ?? '')),
            'timing' => $this->timing->diagnose(
                $server['last_contact_at'] ?? null,
                $server['last_metrics_at'] ?? null
            ),
            'active_incidents' => array_slice($activeIncidents, 0, 25),
            'incident_history' => array_slice($incidentHistory, 0, 25),
            'active_problem_count' => count($activeIncidents),
            'displayMetrics' => $displayMetrics,
            'simpleMetricCharts' => $metricView['simple'],
            'networkCharts' => $metricView['network'],
            'diskIoCharts' => $metricView['disk_io'],
            'temperatureChart' => $metricView['temperature'],
            'diskCharts' => $metricView['disks'],
            'uptimeChart' => $metricView['uptime'],
            'availabilityChart' => $availabilityChart,
            'summaryCards' => $summaryCards,
            'allServices' => $allServices,
            'monitorServices' => $monitorServices,
            'maintenance' => $this->maintenance->active($serverId),
            'latestUptime' => $latestUptime,
            'uptimeText' => $this->metricView->formatUptime($latestUptime['value'] ?? null),
            'startDate' => $startDate->format('Y-m-d\TH:i'),
            'endDate' => $endDate->format('Y-m-d\TH:i'),
            'aggregation' => [
                'source' => $series['source'],
                'seconds' => $series['bucket_seconds'],
            ],
            'totalMinutes' => $totalMinutes,
            'period' => $period,
            'zoom' => $zoom,
        ]);
    }

    /** @param array<string, string> $args */
    public function startMaintenance(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $minutes = filter_var(
            $body['duration_minutes'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 10080]]
        );
        try {
            if ($minutes === false) {
                throw new InvalidArgumentException($this->translator->trans('maintenance.validation.duration'));
            }
            $this->maintenance->start(
                $serverId,
                $minutes * 60,
                is_string($body['reason'] ?? null) ? $body['reason'] : null,
                is_string($_SESSION['username'] ?? null) ? $_SESSION['username'] : null
            );
            $_SESSION['flash_message'] = $this->translator->trans(
                'maintenance.flash.enabled',
                ['minutes' => $minutes]
            );
            $_SESSION['flash_type'] = 'success';
        } catch (InvalidArgumentException $exception) {
            $_SESSION['flash_message'] = $exception->getMessage();
            $_SESSION['flash_type'] = 'danger';
        } catch (Throwable) {
            $_SESSION['flash_message'] = $this->translator->trans('maintenance.flash.enable_failed');
            $_SESSION['flash_type'] = 'danger';
        }

        return $response->withHeader('Location', '/servers/' . $serverId . '?tab=overview')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function cancelMaintenance(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        try {
            $closed = $this->maintenance->cancel($serverId);
            $_SESSION['flash_message'] = $this->translator->trans(
                $closed > 0 ? 'maintenance.flash.ended' : 'maintenance.flash.none'
            );
            $_SESSION['flash_type'] = $closed > 0 ? 'success' : 'warning';
        } catch (Throwable) {
            $_SESSION['flash_message'] = $this->translator->trans('maintenance.flash.end_failed');
            $_SESSION['flash_type'] = 'danger';
        }

        return $response->withHeader('Location', '/servers/' . $serverId . '?tab=overview')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function saveThresholds(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $params = $request->getParsedBody();
        $params = is_array($params) ? $params : [];
        $returnToEdit = ($params['return_to'] ?? null) === 'edit';
        $defaults = $this->servers->thresholdDefaults();
        $thresholds = [];
        $saved = [];
        $usedDefaults = [];
        foreach ($this->metrics->metricTypes($serverId) as $metricType) {
            $warning = $params[$metricType['name'] . '_warning'] ?? '';
            $critical = $params[$metricType['name'] . '_critical'] ?? '';
            $duration = $params[$metricType['name'] . '_duration'] ?? '';
            $recovery = $params[$metricType['name'] . '_recovery'] ?? '';
            if ($warning === '' && $critical === '') {
                continue;
            }
            $warningValue = $warning === '' ? $defaults['warning'] : $this->finiteFloat($warning);
            $criticalValue = $critical === '' ? $defaults['critical'] : $this->finiteFloat($critical);
            $durationValue = $duration === '' ? $defaults['duration'] : filter_var(
                $duration,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 86400]]
            );
            $recoveryValue = $recovery === '' ? $defaults['recovery'] : filter_var(
                $recovery,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 86400]]
            );
            if (
                $warningValue === null
                || $criticalValue === null
                || $durationValue === false
                || $recoveryValue === false
                || $criticalValue < $warningValue
            ) {
                $_SESSION['flash_message'] = $this->translator->trans('threshold.validation');
                $_SESSION['flash_type'] = 'danger';
                return $response->withHeader(
                    'Location',
                    $this->settingsLocation($serverId, 'thresholds', 'thresholds', $returnToEdit)
                )->withStatus(302);
            }
            $thresholds[] = [
                'metric_id' => $metricType['id'],
                'warning' => $warningValue,
                'critical' => $criticalValue,
                'duration' => (int) $durationValue,
                'recovery' => (int) $recoveryValue,
            ];
            $used = [];
            if ($warning === '') {
                $used[] = 'warning=' . $defaults['warning'];
            }
            if ($critical === '') {
                $used[] = 'critical=' . $defaults['critical'];
            }
            if ($duration === '') {
                $used[] = 'duration=' . $defaults['duration'];
            }
            if ($recovery === '') {
                $used[] = 'recovery=' . $defaults['recovery'];
            }
            if ($used !== []) {
                $usedDefaults[] = $metricType['name'] . ' (' . implode(', ', $used) . ')';
            } else {
                $saved[] = $metricType['name'];
            }
        }
        $this->servers->replaceThresholds($serverId, $thresholds);
        $messages = [];
        if ($saved !== []) {
            $messages[] = $this->translator->trans('threshold.saved', ['items' => implode(', ', $saved)]);
        }
        if ($usedDefaults !== []) {
            $messages[] = $this->translator->trans(
                'threshold.defaults_used',
                ['items' => implode(', ', $usedDefaults)]
            );
        }
        if ($messages === []) {
            $messages[] = $this->translator->trans('threshold.all_removed');
        }
        $_SESSION['flash_message'] = implode('. ', $messages);
        $_SESSION['flash_type'] = $usedDefaults !== [] ? 'warning' : 'success';

        return $response->withHeader(
            'Location',
            $this->settingsLocation($serverId, 'thresholds', 'metrics', $returnToEdit)
        )->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function saveServices(Request $request, Response $response, array $args): Response
    {
        $serverId = $this->serverId($args);
        if ($serverId === null) {
            return $response->withHeader('Location', '/servers')->withStatus(302);
        }
        $params = $request->getParsedBody();
        $params = is_array($params) ? $params : [];
        $returnToEdit = ($params['return_to'] ?? null) === 'edit';
        $services = $params['services'] ?? [];
        if (is_string($services)) {
            try {
                $services = json_decode($services, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $services = [];
            }
        }
        $services = is_array($services) ? $services : [];
        $services = array_slice(array_values(array_unique(array_filter(
            $services,
            static fn (mixed $service): bool => is_string($service)
                && $service !== ''
                && strlen($service) <= 255
        ))), 0, 100);
        $this->servers->saveMonitoredServices($serverId, $services);
        if ($returnToEdit) {
            $_SESSION['flash_message'] = $this->translator->trans('server18.settings.services_saved');
            $_SESSION['flash_type'] = 'success';
        }

        return $response->withHeader(
            'Location',
            $this->settingsLocation($serverId, 'services-monitoring', 'services', $returnToEdit)
        )->withStatus(302);
    }

    /** @param array<string, mixed> $args */
    private function serverId(array $args): ?int
    {
        $serverId = filter_var(
            $args['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        return $serverId === false ? null : $serverId;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function metricRange(array $query, string $period, ?string $zoom): array
    {
        $end = new DateTimeImmutable();
        $start = $end->modify(self::PERIODS[$period]);
        if (is_string($query['start'] ?? null) && is_string($query['end'] ?? null)) {
            try {
                $requestedStart = new DateTimeImmutable($query['start']);
                $requestedEnd = new DateTimeImmutable($query['end']);
                if ($requestedEnd > $requestedStart) {
                    $start = $requestedStart;
                    $end = $requestedEnd;
                }
            } catch (Throwable) {
                // Keep the selected safe period for malformed optional dates.
            }
        }
        if ($zoom !== null) {
            $zoomStart = $end->modify(self::PERIODS[$zoom]);
            if ($zoomStart > $start) {
                $start = $zoomStart;
            }
        }
        $maximumStart = $end->modify('-2 years');
        if ($start < $maximumStart) {
            $start = $maximumStart;
        }
        return [$start, $end];
    }

    /**
     * @param list<string> $displayMetrics
     * @return list<string>
     */
    private function expandDisplayMetrics(array $displayMetrics): array
    {
        $expanded = [];
        foreach ($displayMetrics as $metricName) {
            if ($metricName === 'availability') {
                continue;
            }
            $expanded[$metricName] = $metricName;
            if ($metricName === 'ram_used') {
                $expanded['ram_total_gb'] = 'ram_total_gb';
            }
            if (str_starts_with($metricName, 'disk_used_')) {
                $suffix = substr($metricName, strlen('disk_used_'));
                $expanded['disk_total_gb_' . $suffix] = 'disk_total_gb_' . $suffix;
            }
        }
        return array_values($expanded);
    }

    /** @param list<string>|null $displayMetrics */
    private function metricSelected(string $metricName, ?array $displayMetrics): bool
    {
        return is_array($displayMetrics) && in_array($metricName, $displayMetrics, true);
    }

    private function architecture(string $artifact): ?string
    {
        if ($artifact === '') {
            return null;
        }
        if (str_ends_with($artifact, '-amd64')) {
            return 'x86-64 (amd64)';
        }
        if (str_ends_with($artifact, '-arm64')) {
            return 'ARM64 (aarch64)';
        }
        return null;
    }

    private function settingsLocation(
        int $serverId,
        string $anchor,
        string $fallbackTab,
        bool $returnToEdit
    ): string {
        if ($returnToEdit) {
            return '/servers/' . $serverId . '/edit#' . $anchor;
        }
        return '/servers/' . $serverId . '?tab=' . $fallbackTab;
    }

    private function finiteFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }
}
