<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Integrations\Mcp;

use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\Tracing\Span;

/** Internal lifetime state for one observed execution. */
final readonly class ActiveExecution
{
    public function __construct(
        public Span $span,
        public TelemetryManager $manager,
        public bool $ownsContext,
    ) {}
}
