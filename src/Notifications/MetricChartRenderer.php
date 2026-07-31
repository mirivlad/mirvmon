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
        if ($serverId === false || $metric === '') {
            return null;
        }

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
        ?float $threshold
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
            imageline(
                $image,
                (int) round($plotLeft + ($previousAt - $firstAt) / $duration * $plotWidth),
                $y($previousValue),
                (int) round($plotLeft + ($currentAt - $firstAt) / $duration * $plotWidth),
                $y($currentValue),
                $line
            );
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
