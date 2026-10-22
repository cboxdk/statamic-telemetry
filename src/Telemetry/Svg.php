<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * Tiny server-side SVG chart helpers for the native Telemetry page. Charts are
 * rendered as inline SVG from the query results — no JS build, no client
 * library — and inherit their colour from `currentColor`, so the CP stylesheet
 * themes them. Every path is clamped to the viewBox and safe to echo.
 */
final class Svg
{
    /**
     * A filled line chart (area under a polyline) from a list of y-values.
     *
     * @param  list<int|float>  $points
     */
    public static function area(array $points, int $width = 640, int $height = 120): string
    {
        $points = array_map(static fn ($p): float => (float) $p, $points);
        $count = count($points);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            $points = [$points[0], $points[0]];
            $count = 2;
        }

        $max = max($points);
        $max = $max > 0.0 ? $max : 1.0;
        $pad = 4.0;
        $usableH = $height - $pad * 2;
        $stepX = $width / ($count - 1);

        $coords = [];
        foreach ($points as $i => $p) {
            $x = round($i * $stepX, 2);
            $y = round($pad + $usableH - ($p / $max) * $usableH, 2);
            $coords[] = [$x, $y];
        }

        $line = 'M'.implode(' L', array_map(static fn (array $c): string => $c[0].','.$c[1], $coords));
        $area = $line.' L'.$width.','.$height.' L0,'.$height.' Z';

        return '<svg class="ptel-chart" viewBox="0 0 '.$width.' '.$height.'" preserveAspectRatio="none" role="img" aria-hidden="true">'
            .'<path class="ptel-chart-area" d="'.$area.'"/>'
            .'<path class="ptel-chart-line" d="'.$line.'" fill="none"/>'
            .'</svg>';
    }

    /**
     * A horizontal bar [0..1] — a proportion meter (e.g. a latency relative to
     * the slowest, a hit ratio).
     */
    public static function meter(float $fraction): string
    {
        $pct = max(0.0, min(1.0, $fraction)) * 100;

        return '<span class="ptel-meter"><span class="ptel-meter-fill" style="width: '.round($pct, 1).'%"></span></span>';
    }
}
