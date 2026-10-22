<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/** One span in a single trace's waterfall. */
final class TraceSpan
{
    public function __construct(
        public readonly string $name,
        public readonly int $startNano,
        public readonly float $durationMs,
        public readonly int $depth,
    ) {}
}
