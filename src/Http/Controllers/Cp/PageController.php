<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Cp\TelemetryNav;
use Cbox\StatamicTelemetry\Telemetry\TelemetryPages;
use Cbox\StatamicTelemetry\Telemetry\TimeRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * One of the purpose-built Statamic pages (static cache, Stache, Glide,
 * forms, content): the aggregate on top, the rows underneath. Thin —
 * {@see TelemetryPages} builds both; this only reads the request.
 */
class PageController extends CpController
{
    public function __invoke(Request $request, string $page, TelemetryPages $pages, TelemetryNav $nav): Response
    {
        abort_unless(TelemetryPages::exists($page), 404);

        $range = TimeRange::fromRequest($request);
        $q = trim((string) $request->query('q', ''));
        $filters = $pages->filters($page, $request);

        return Inertia::render('statamic-telemetry::AreaPage', [
            'page' => $pages->meta($page),
            'nav' => $nav->items($page),
            ...$range->toProps(),
            'exploreUrl' => cp_route('telemetry.explore'),
            'q' => $q,
            'filters' => $filters,
            'aggregate' => fn () => $pages->aggregate($page, $range),
            // Searching or filtering reloads only the rows.
            'rows' => fn () => $pages->rows($page, $range, $q, $filters, $request),
        ]);
    }
}
