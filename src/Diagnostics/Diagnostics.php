<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Diagnostics;

use Cbox\StatamicTelemetry\Audit\Backend;
use Cbox\Telemetry\Exporters\Spool\Spool;
use Throwable;

/**
 * Gathers the telemetry pipeline's health for the CP doctor page: the switches
 * and exporters, the OTLP spool depth (entries still waiting to ship), and
 * whether the audit log's logs backend answers. A read-only mirror of the key
 * signals `php artisan telemetry:doctor` prints — never throwing, so the page
 * renders even when the pipeline is broken (explaining the break is its job).
 */
final class Diagnostics
{
    public function __construct(private readonly Backend $backend) {}

    public function gather(): DiagnosticsResult
    {
        [$spoolSize, $spoolError] = $this->spoolSize();
        [$reachable, $detail] = $this->probeBackend();

        $spoolEnabled = (bool) config('telemetry.otlp.spool.enabled', false);

        return new DiagnosticsResult(
            telemetryEnabled: (bool) config('telemetry.enabled', true),
            overlayEnabled: (bool) config('statamic-telemetry.enabled', true),
            serviceName: (string) config('telemetry.service.name', 'laravel'),
            storeDriver: (string) config('telemetry.store', 'redis'),
            otlpEndpoint: (string) config('telemetry.otlp.endpoint', ''),
            exporters: $this->exporters(),
            spoolEnabled: $spoolEnabled,
            spoolDriver: $spoolEnabled ? (string) config('telemetry.otlp.spool.driver', 'redis') : null,
            spoolSize: $spoolSize,
            spoolError: $spoolError,
            backendConfigured: $this->backend->configured(),
            backendUrl: $this->backend->url(),
            backendReachable: $reachable,
            backendDetail: $detail,
            hasToken: $this->backend->hasToken(),
            hasTenant: $this->backend->hasTenant(),
        );
    }

    /**
     * The spool's current depth. The binding always exists, but a spool
     * pointed at an unreachable Redis or an unwritable file must not break the
     * page — that is exactly what the doctor is here to report.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function spoolSize(): array
    {
        try {
            return [app(Spool::class)->size(), null];
        } catch (Throwable $exception) {
            return [null, $exception->getMessage()];
        }
    }

    /**
     * Can we read the audit log's backend? A cheap GET to Loki's label API,
     * guarded the same way the reader is.
     *
     * @return array{0: bool|null, 1: string}
     */
    private function probeBackend(): array
    {
        if (! $this->backend->configured()) {
            return [null, 'No logs URL configured — the Content audit page is off.'];
        }

        try {
            $response = $this->backend->request()->get($this->backend->endpoint('loki/api/v1/labels'));
        } catch (Throwable $exception) {
            return [false, 'Unreachable: '.$exception->getMessage()];
        }

        if (! $response->successful()) {
            return [false, 'The backend returned HTTP '.$response->status().'.'];
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'success') {
            return [false, 'The URL answered, but not with a Loki log API.'];
        }

        return [true, 'Reachable — the Loki query API answered.'];
    }

    /**
     * @return list<string>
     */
    private function exporters(): array
    {
        $exporters = config('telemetry.exporters', []);

        if (! is_array($exporters)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $exporters),
            static fn (?string $value): bool => $value !== null && $value !== '',
        ));
    }
}
