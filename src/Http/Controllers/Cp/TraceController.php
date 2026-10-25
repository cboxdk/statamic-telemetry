<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Telemetry\Logs;
use Cbox\StatamicTelemetry\Telemetry\Traces;
use Cbox\StatamicTelemetry\Telemetry\TraceSpan;
use Illuminate\Http\JsonResponse;
use Statamic\Http\Controllers\CP\CpController;

/**
 * One trace as JSON for the trace drawer: its spans (for the waterfall), the
 * root span's headline, the services involved and the log lines that carry
 * its trace id. The drawer fetches this itself, so it can open from any page
 * — Overview, Explore, an entity table — without that page reloading.
 */
class TraceController extends CpController
{
    public function __invoke(string $id, Traces $traces, Logs $logs): JsonResponse
    {
        // Authorised by the route's `can:view telemetry` middleware.
        $spans = $traces->trace($id);
        $root = $this->root($spans);

        return response()->json([
            'id' => $id,
            'root' => $root === null ? null : [
                'name' => $root->name,
                'service' => $root->service,
                'kind' => $root->kind,
                'durationMs' => $root->durationMs,
                'status' => $root->attributes['http.response.status_code'] ?? null,
                'error' => str_starts_with($root->attributes['status'] ?? '', 'error'),
            ],
            'spans' => $spans,
            'services' => array_values(array_unique(array_filter(array_map(
                static fn (TraceSpan $s): ?string => $s->service,
                $spans,
            )))),
            'logs' => $spans === [] ? [] : $logs->forTrace($id),
        ]);
    }

    /**
     * The root span: the shallowest one, longest first when several share it.
     *
     * @param  list<TraceSpan>  $spans
     */
    private function root(array $spans): ?TraceSpan
    {
        $root = null;

        foreach ($spans as $span) {
            if ($root === null || $span->depth < $root->depth || ($span->depth === $root->depth && $span->durationMs > $root->durationMs)) {
                $root = $span;
            }
        }

        return $root;
    }
}
