<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Illuminate\Http\Request;

/**
 * The time range a telemetry page is looking at — the one model every
 * controller, assembler and link shares, so window / custom range state is
 * never dropped between pages.
 *
 * Either a look-back **window** anchored at now (`1h`), or a brushed **custom
 * range** (`from`/`to`, unix seconds) that anchors at its own end. Assemblers
 * take `($seconds, $endsAt)`: `endsAt` is null for a window (= now), the range
 * end for a custom range.
 */
final class TimeRange
{
    /** Look-back windows offered in the period control. Value → label. */
    public const WINDOWS = [
        '5m' => 'Last 5 min',
        '15m' => 'Last 15 min',
        '30m' => 'Last 30 min',
        '1h' => 'Last hour',
        '3h' => 'Last 3 hours',
        '6h' => 'Last 6 hours',
        '12h' => 'Last 12 hours',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
    ];

    public const DEFAULT = '1h';

    /** The longest custom range accepted (also bounds query cost). */
    private const MAX_SECONDS = 7 * 86400;

    private function __construct(
        public readonly string $window,
        public readonly int $seconds,
        public readonly ?int $endsAt,
    ) {}

    /**
     * Resolve from the request: a valid `from`/`to` pair wins, otherwise the
     * `window` (falling back to the default). The window is kept alongside a
     * custom range so "reset" can return to it.
     */
    public static function fromRequest(Request $request): self
    {
        $window = self::validWindow($request->query('window'));
        $from = $request->query('from');
        $to = $request->query('to');

        if (is_numeric($from) && is_numeric($to)) {
            $from = (int) $from;
            $to = (int) $to;
            $span = $to - $from;

            if ($span >= 60 && $span <= self::MAX_SECONDS && $to <= time() + 300) {
                return new self($window, $span, $to);
            }
        }

        return self::window($window);
    }

    public static function window(string $window): self
    {
        $window = self::validWindow($window);

        return new self($window, self::secondsFor($window), null);
    }

    public function isCustom(): bool
    {
        return $this->endsAt !== null;
    }

    /** Range start, unix seconds. */
    public function from(): int
    {
        return $this->to() - $this->seconds;
    }

    /** Range end, unix seconds (now for a window). */
    public function to(): int
    {
        return $this->endsAt ?? time();
    }

    /**
     * The props every telemetry page shares, so the shell's period control
     * and `useTelemetryUrl()` always carry the same state.
     *
     * @return array{window: string, windows: array<string, string>, from: ?int, to: ?int}
     */
    public function toProps(): array
    {
        return [
            'window' => $this->window,
            'windows' => self::WINDOWS,
            'from' => $this->isCustom() ? $this->from() : null,
            'to' => $this->endsAt,
        ];
    }

    /** `15m` / `6h` / `7d` → seconds. Unknown input is the default window. */
    public static function secondsFor(string $window): int
    {
        if (preg_match('/^(\d+)([mhd])$/', $window, $m) !== 1) {
            return self::secondsFor(self::DEFAULT);
        }

        return (int) $m[1] * match ($m[2]) {
            'd' => 86400,
            'h' => 3600,
            default => 60,
        };
    }

    private static function validWindow(mixed $window): string
    {
        return is_string($window) && isset(self::WINDOWS[$window]) ? $window : self::DEFAULT;
    }
}
