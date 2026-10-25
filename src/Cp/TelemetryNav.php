<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Cp;

use Cbox\StatamicTelemetry\Telemetry\StatamicPages;
use Illuminate\Support\Facades\Gate;

/**
 * The telemetry pages' own navigation — the second tier under the sidebar's
 * "Telemetry" item, rendered by every telemetry page so it never disappears.
 * Concrete pages, not buckets: Overview, Explore, Activity, then Statamic's
 * own areas (static cache, Stache, Glide, forms, content), then the doctor.
 * The server owns the list; the client adds the carried time state to each
 * href.
 */
final class TelemetryNav
{
    /**
     * @return list<array{key: string, label: string, path: string, params: array<string, string>, active: bool}>
     */
    public function items(string $active): array
    {
        $items = [];

        if (Gate::allows('view telemetry')) {
            $items[] = $this->item('overview', 'Overview', cp_route('telemetry.dashboard'), [], $active);
            $items[] = $this->item('explore', 'Explore', cp_route('telemetry.explore'), [], $active);
            $items[] = $this->item('activity', 'Activity', cp_route('telemetry.dashboard'), ['section' => 'activity'], $active);

            foreach (StatamicPages::PAGES as $key => $page) {
                $items[] = $this->item($key, $page['title'], cp_route('telemetry.page', ['page' => $key]), [], $active);
            }
        }

        if (Gate::allows('view telemetry diagnostics')) {
            $items[] = $this->item('doctor', 'Doctor', cp_route('telemetry.doctor'), [], $active);
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $params
     * @return array{key: string, label: string, path: string, params: array<string, string>, active: bool}
     */
    private function item(string $key, string $label, string $path, array $params, string $active): array
    {
        return ['key' => $key, 'label' => $label, 'path' => $path, 'params' => $params, 'active' => $key === $active];
    }
}
