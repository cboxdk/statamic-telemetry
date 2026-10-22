<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/** One row in the traces list: a request, its name, duration and when. */
final class TraceSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $service,
        public readonly float $durationMs,
        public readonly int $timestampMs,
    ) {}
}
