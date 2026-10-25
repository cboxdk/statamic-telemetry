<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/** One span in a single trace's waterfall, with its attributes for the detail view. */
final class TraceSpan
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly int $startNano,
        public readonly float $durationMs,
        public readonly int $depth,
        public readonly ?string $service = null,
        public readonly ?string $kind = null,
        public readonly array $attributes = [],
    ) {}
}
