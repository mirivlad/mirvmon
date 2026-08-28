<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Metrics\MetricValueFormatter;
use PDO;

/**
 * Draws the alerting metric so the message answers "was it a spike or a
 * climb?" without opening the dashboard. Deliberately hand-drawn with GD:
 * the image ships to Telegram and email, where a chart library would need a
 * browser.
 */
final class MetricChartRenderer
{
    private const WIDTH = 900;
    private const HEIGHT = 320;
    private const PADDING_LEFT = 78;
    private const PADDING_RIGHT = 20;
    private const PADDING_TOP = 34;
    private const PADDING_BOTTOM = 34;
    private const MAX_POINTS = 240;
    /** Ships with fonts-dejavu-core and covers Cyrillic server names. */
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    public function __construct(
        private readonly PDO $pdo,
        private readonly MetricValueFormatter $formatter = new MetricValueFormatter()
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return string|null PNG bytes, or null when there is nothing to draw.
     */
    public function render(array $payload, int $windowSeconds = 3600): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $serverId = filter_var(
            $payload['server_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $metric = trim((string) ($payload['metric'] ?? ''));
        if ($serverId !== false && $metric !== '') {
            $series = $this->series($serverId, $metric, $windowSeconds);
            if (count($series) < 2) {
                return null;
            }

            return $this->draw(
                $series,
                $metric,
                $this->unit($metric),
                (string) ($payload['server_name'] ?? ''),
                $this->threshold($payload)
            );
        }

        if ($serverId !== false && ($payload['type'] ?? null) === 'offline') {
            $series = $this->availabilitySeries($serverId, $windowSeconds);
            if (count($series) < 2) {
                return null;
            }

            return $this->draw(
                $series,
                'Доступность',
                '%',
                (string) ($payload['server_name'] ?? ''),
                null,
                true
            );
        }

        $websiteId = filter_var(
            $payload['website_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $endpointId = filter_var(
            $payload['endpoint_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $kind = trim((string) ($payload['type'] ?? $payload['kind'] ?? ''));
        if ($websiteId === false || $endpointId === false) {
            return null;
        }

        $websiteMetric = match ($kind) {
            'website_http' => 'transport_available',
            'website_assertion' => 'assertions_passed',
            'website_performance' => 'total_ms',
            default => null,
        };
        if ($websiteMetric === null) {
            return null;
        }

        $series = $this->websiteSeries($websiteId, $endpointId, $websiteMetric, $windowSeconds);
        if (count($series) < 2) {
            return null;
        }

        $label = match ($websiteMetric) {
            'transport_available' => 'Доступность',
            'assertions_passed' => 'Проверки содержимого',
            default => 'Время ответа',
        };
        $source = trim((string) ($payload['website_name'] ?? ''));
        $endpoint = trim((string) ($payload['endpoint_name'] ?? ''));
        if ($endpoint !== '') {
            $source = $source === '' ? $endpoint : $source . ' / ' . $endpoint;
        }

        return $this->draw(
            $series,
            $label,
            $websiteMetric === 'total_ms' ? 'ms' : '%',
            $source,
            $websiteMetric === 'total_ms'
                ? $this->websiteThreshold($endpointId, (string) ($payload['severity'] ?? 'critical'))
                : null,
            $websiteMetric !== 'total_ms'
        );
    }

    /**
     * @return list<array{0: int, 1: float}> Unix time and value.
     */
    private function availabilitySeries(int $serverId, int $windowSeconds): array
    {
        $start = time() - $windowSeconds;
        $before = $this->pdo->prepare(
            'SELECT state, CAST(EXTRACT(EPOCH FROM occurred_at) AS bigint) AS at
             FROM server_availability_events
             WHERE server_id = :server_id AND occurred_at <= to_timestamp(:start)
             ORDER BY occurred_at DESC, id DESC LIMIT 1'
        );
        $before->execute(['server_id' => $serverId, 'start' => $start]);
        $initial = $before->fetch();

        $events = $this->pdo->prepare(
            'SELECT state, CAST(EXTRACT(EPOCH FROM occurred_at) AS bigint) AS at
             FROM server_availability_events
             WHERE server_id = :server_id AND occurred_at > to_timestamp(:start)
             ORDER BY occurred_at, id'
        );
        $events->execute(['server_id' => $serverId, 'start' => $start]);
        $rows = $events->fetchAll();

        if (!is_array($initial) && $rows === []) {
            return [];
        }

        if (is_array($initial)) {
            $state = (string) $initial['state'];
            $points = [[$start, $state === 'online' ? 100.0 : 0.0]];
        } else {
            $first = array_shift($rows);
            if (!is_array($first)) {
                return [];
            }
            $state = (string) $first['state'];
            $points = [[(int) $first['at'], $state === 'online' ? 100.0 : 0.0]];
        }

        foreach ($rows as $row) {
            $state = (string) $row['state'];
            $points[] = [(int) $row['at'], $state === 'online' ? 100.0 : 0.0];
        }
        $points[] = [time(), $state === 'online' ? 100.0 : 0.0];

        return $this->downsample($points);
    }

    /**
     * @return list<array{0: int, 1: float}> Unix time and value.
     */
    private function websiteSeries(int $websiteId, int $endpointId, string $metric, int $windowSeconds): array
    {
        $column = match ($metric) {
            'transport_available' => 'transport_available::integer * 100',
            'assertions_passed' => 'assertions_passed::integer * 100',
            'total_ms' => 'total_ms',
            default => throw new \InvalidArgumentException('Unsupported website chart metric.'),
        };
        $statement = $this->pdo->prepare(
            "SELECT CAST(EXTRACT(EPOCH FROM sample_time) AS bigint) AS at, {$column} AS value
             FROM website_check_samples
             WHERE website_id = :website_id
               AND endpoint_id = :endpoint_id
               AND sample_time > CURRENT_TIMESTAMP - CAST(:window AS integer) * INTERVAL '1 second'
               AND {$column} IS NOT NULL
             ORDER BY sample_time"
        );
        $statement->execute([
            'website_id' => $websiteId,
            'endpoint_id' => $endpointId,
            'window' => $windowSeconds,
        ]);

        $points = [];
        foreach ($statement->fetchAll() as $row) {
            $points[] = [(int) $row['at'], (float) $row['value']];
        }

        return $this->downsample($points);
    }

    private function websiteThreshold(int $endpointId, string $severity): ?float
    {
        $column = $severity === 'warning' ? 'warning_total_ms' : 'critical_total_ms';
        $statement = $this->pdo->prepare(
            "SELECT {$column} FROM website_endpoints WHERE id = :endpoint_id"
        );
        $statement->execute(['endpoint_id' => $endpointId]);
        $value = $statement->fetchColumn();

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<array{0: int, 1: float}> Unix time and value.
     */
    private function series(int $serverId, string $metric, int $windowSeconds): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                CAST(EXTRACT(EPOCH FROM samples.sample_time) AS bigint) AS at,
                samples.value
             FROM metric_samples AS samples
             INNER JOIN metric_names AS names ON names.id = samples.metric_id
             WHERE samples.server_id = :server_id
               AND names.name = :metric
               AND samples.sample_time > CURRENT_TIMESTAMP
                   - CAST(:window AS integer) * INTERVAL '1 second'
             ORDER BY samples.sample_time"
        );
        $statement->execute([
            'server_id' => $serverId,
            'metric' => $metric,
            'window' => $windowSeconds,
        ]);

        $points = [];
        foreach ($statement->fetchAll() as $row) {
            $points[] = [(int) $row['at'], (float) $row['value']];
        }

        return $this->downsample($points);
    }

    /**
     * @param list<array{0: int, 1: float}> $points
     * @return list<array{0: int, 1: float}>
     */
    private function downsample(array $points): array
    {
        $total = count($points);
        if ($total <= self::MAX_POINTS) {
            return $points;
        }

        $step = $total / self::MAX_POINTS;
        $reduced = [];
        for ($index = 0; $index < self::MAX_POINTS; $index++) {
            $reduced[] = $points[(int) floor($index * $step)];
        }
        $reduced[] = $points[$total - 1];

        return $reduced;
    }

    private function unit(string $metric): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT unit FROM metric_names WHERE name = :metric'
        );
        $statement->execute(['metric' => $metric]);
        $unit = $statement->fetchColumn();

        return is_string($unit) && $unit !== '' ? $unit : null;
    }

    /** @param array<string, mixed> $payload */
    private function threshold(array $payload): ?float
    {
        $value = $payload['threshold'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The built-in GD font is single byte, so a Cyrillic server name would
     * come out as noise. Draw with the bundled TrueType face when it is
     * available and fall back to ASCII-only built-in text otherwise.
     */
    private function text(
        \GdImage $image,
        string $value,
        int $x,
        int $baseline,
        int $size,
        int $colour
    ): void {
        if (function_exists('imagettftext') && is_readable(self::FONT)) {
            imagettftext($image, $size, 0, $x, $baseline, $colour, self::FONT, $value);

            return;
        }

        $ascii = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
        imagestring($image, $size >= 13 ? 4 : 2, $x, $baseline - 12, $ascii, $colour);
    }

    /**
     * @param list<array{0: int, 1: float}> $series
     */
    private function draw(
        array $series,
        string $metric,
        ?string $unit,
        string $serverName,
        ?float $threshold,
        bool $stepped = false
    ): ?string {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($image === false) {
            return null;
        }

        $background = (int) imagecolorallocate($image, 255, 255, 255);
        $grid = (int) imagecolorallocate($image, 226, 232, 240);
        $axis = (int) imagecolorallocate($image, 148, 163, 184);
        $ink = (int) imagecolorallocate($image, 30, 41, 59);
        $line = (int) imagecolorallocate($image, 13, 110, 253);
        $alarm = (int) imagecolorallocate($image, 220, 53, 69);
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $background);

        $values = array_map(static fn (array $point): float => $point[1], $series);
        $minimum = min($values);
        $maximum = max($values);
        if ($threshold !== null) {
            $minimum = min($minimum, $threshold);
            $maximum = max($maximum, $threshold);
        }
        if ($maximum - $minimum < 0.0001) {
            $maximum = $minimum + 1.0;
        }
        $span = $maximum - $minimum;
        $minimum -= $span * 0.08;
        $maximum += $span * 0.08;

        $plotLeft = self::PADDING_LEFT;
        $plotRight = self::WIDTH - self::PADDING_RIGHT;
        $plotTop = self::PADDING_TOP;
        $plotBottom = self::HEIGHT - self::PADDING_BOTTOM;
        $plotWidth = $plotRight - $plotLeft;
        $plotHeight = $plotBottom - $plotTop;

        $y = static function (float $value) use (
            $minimum,
            $maximum,
            $plotBottom,
            $plotHeight
        ): int {
            $ratio = ($value - $minimum) / ($maximum - $minimum);

            return (int) round($plotBottom - $ratio * $plotHeight);
        };

        for ($step = 0; $step <= 4; $step++) {
            $value = $minimum + ($maximum - $minimum) * $step / 4;
            $row = $y($value);
            imageline($image, $plotLeft, $row, $plotRight, $row, $grid);
            $this->text(
                $image,
                $this->formatter->format($value, $unit),
                4,
                $row + 4,
                10,
                $ink
            );
        }
        imageline($image, $plotLeft, $plotTop, $plotLeft, $plotBottom, $axis);
        imageline($image, $plotLeft, $plotBottom, $plotRight, $plotBottom, $axis);

        if ($threshold !== null) {
            $row = $y($threshold);
            for ($x = $plotLeft; $x < $plotRight; $x += 8) {
                imageline($image, $x, $row, min($x + 4, $plotRight), $row, $alarm);
            }
        }

        $firstAt = $series[0][0];
        $lastAt = $series[count($series) - 1][0];
        $duration = max(1, $lastAt - $firstAt);
        imagesetthickness($image, 2);
        for ($index = 1, $total = count($series); $index < $total; $index++) {
            [$previousAt, $previousValue] = $series[$index - 1];
            [$currentAt, $currentValue] = $series[$index];
            $previousX = (int) round($plotLeft + ($previousAt - $firstAt) / $duration * $plotWidth);
            $currentX = (int) round($plotLeft + ($currentAt - $firstAt) / $duration * $plotWidth);
            if ($stepped) {
                imageline($image, $previousX, $y($previousValue), $currentX, $y($previousValue), $line);
                imageline($image, $currentX, $y($previousValue), $currentX, $y($currentValue), $line);
            } else {
                imageline($image, $previousX, $y($previousValue), $currentX, $y($currentValue), $line);
            }
        }
        imagesetthickness($image, 1);

        $title = $serverName === '' ? $metric : $serverName . ' / ' . $metric;
        $this->text($image, $title, self::PADDING_LEFT, 22, 13, $ink);
        $this->text(
            $image,
            gmdate('H:i', $firstAt) . ' - ' . gmdate('H:i', $lastAt) . ' UTC',
            self::PADDING_LEFT,
            $plotBottom + 22,
            10,
            $ink
        );

        ob_start();
        imagepng($image, null, 6);
        $png = ob_get_clean();
        imagedestroy($image);

        return is_string($png) && $png !== '' ? $png : null;
    }
}
