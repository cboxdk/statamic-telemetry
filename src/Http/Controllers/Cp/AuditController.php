<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Cbox\StatamicTelemetry\Audit\AuditQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Content audit" CP page: reads the content-change trail back from
 * the telemetry backend and renders it in the Control Panel. Thin — the read,
 * filtering and pagination all live in {@see AuditLog}.
 */
class AuditController extends CpController
{
    public function __invoke(Request $request, AuditLog $log): View
    {
        abort_unless($log->enabled(), 404);

        $query = AuditQuery::fromRequest($request);

        $data = [
            'query' => $query,
            'result' => $log->query($query),
            'windows' => AuditQuery::WINDOWS,
        ];

        return view('statamic-telemetry::cp.audit', $data); // @phpstan-ignore argument.type
    }
}
