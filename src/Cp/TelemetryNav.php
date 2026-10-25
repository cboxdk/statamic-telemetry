<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Cp;

use Cbox\StatamicTelemetry\Telemetry\TelemetryPages;
use Illuminate\Support\Facades\Gate;

/**
 * The telemetry pages' own navigation — the second tier under the sidebar's
 * "Telemetry" item, rendered by every telemetry page so it never disappears.
 * Concrete pages, not buckets, in groups: Overview and Explore; the
 * application's activity (requests, jobs, queries, exceptions, outgoing);
 * Statamic's own areas (static cache, Stache, Glide, forms, content). The
 * server owns the list; the client adds the carried time state to each href
 * and draws a divider between groups.
 */
final class TelemetryNav
{
    /**
     * @return list<array{key: string, label: string, path: string, params: array<string, string>, group: string, active: bool}>
     */
    public function items(string $active): array
    {
        $items = [];

        if (Gate::allows('view telemetry')) {
            $items[] = $this->item('overview', 'Overview', cp_route('telemetry.dashboard'), 'main', $active);
            $items[] = $this->item('explore', 'Explore', cp_route('telemetry.explore'), 'main', $active);

            foreach (TelemetryPages::PAGES as $key => $page) {
                $items[] = $this->item($key, $page['short'] ?? $page['title'], cp_route('telemetry.page', ['page' => $key]), $page['group'], $active);
            }
        }

        // The doctor is diagnostics, not data: it lives in the sidebar only,
        // which also keeps the data pages on one line at 1280px.
        return $items;
    }

    /**
     * @return array{key: string, label: string, path: string, params: array<string, string>, group: string, active: bool}
     */
    private function item(string $key, string $label, string $path, string $group, string $active): array
    {
        return ['key' => $key, 'label' => $label, 'path' => $path, 'params' => [], 'group' => $group, 'active' => $key === $active];
    }
}
