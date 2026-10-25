<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Cp;

use Cbox\StatamicTelemetry\Audit\AuditLog;
use Illuminate\Support\Facades\Gate;

/**
 * The telemetry pages' own navigation — the second tier under the sidebar's
 * "Telemetry" item, rendered by every telemetry page so it never disappears.
 * The server owns the list (and, later, hides pages whose metrics aren't
 * flowing); the client adds the carried time state to each href.
 */
final class TelemetryNav
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @return list<array{key: string, label: string, path: string, params: array<string, string>, active: bool}>
     */
    public function items(string $active): array
    {
        $can = static fn (string $permission): bool => Gate::allows($permission);

        $items = [];

        if ($can('view telemetry')) {
            $items[] = $this->item('overview', 'Overview', cp_route('telemetry.dashboard'), [], $active);
            $items[] = $this->item('explore', 'Explore', cp_route('telemetry.explore'), [], $active);
            $items[] = $this->item('activity', 'Activity', cp_route('telemetry.dashboard'), ['section' => 'activity'], $active);
            $items[] = $this->item('statamic', 'Statamic', cp_route('telemetry.dashboard'), ['section' => 'statamic'], $active);
        }

        if ($this->audit->enabled() && $can('view content audit log')) {
            $items[] = $this->item('audit', 'Content audit', cp_route('telemetry.audit'), [], $active);
        }

        if ($can('view telemetry diagnostics')) {
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
