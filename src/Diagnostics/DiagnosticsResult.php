<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Diagnostics;

/**
 * A snapshot of the telemetry pipeline's health, as the doctor page shows it:
 * is telemetry on, where does it ship, how deep is the spool, and can we read
 * the audit log's backend back. Every field is already resolved and safe to
 * render — gathering never throws.
 */
final class DiagnosticsResult
{
    /**
     * @param  list<string>  $exporters
     */
    public function __construct(
        public readonly bool $telemetryEnabled,
        public readonly bool $overlayEnabled,
        public readonly string $serviceName,
        public readonly string $storeDriver,
        public readonly string $otlpEndpoint,
        public readonly array $exporters,
        public readonly bool $spoolEnabled,
        public readonly ?string $spoolDriver,
        public readonly ?int $spoolSize,
        public readonly ?string $spoolError,
        public readonly bool $backendConfigured,
        public readonly ?string $backendUrl,
        public readonly ?bool $backendReachable,
        public readonly string $backendDetail,
        public readonly bool $hasToken,
        public readonly bool $hasTenant,
    ) {}
}
