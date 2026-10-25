<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Cbox\StatamicTelemetry\Audit\AuditQuery;
use Cbox\StatamicTelemetry\Cp\TelemetryNav;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Content audit" CP page: reads the content-change trail back from
 * the telemetry backend and renders it in the Control Panel. Thin — the read,
 * filtering and pagination all live in {@see AuditLog}.
 */
class AuditController extends CpController
{
    public function __invoke(Request $request, AuditLog $log, TelemetryNav $nav): Response
    {
        abort_unless($log->enabled(), 404);

        $query = AuditQuery::fromRequest($request);

        return Inertia::render('statamic-telemetry::ContentAudit', [
            'nav' => $nav->items('audit'),
            'query' => $query,
            'result' => $log->query($query),
            'windows' => AuditQuery::WINDOWS,
        ]);
    }
}
