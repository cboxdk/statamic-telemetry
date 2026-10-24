<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

use Cbox\StatamicTelemetry\Audit\Backend;
use Throwable;

/**
 * Reads deployment markers back from the telemetry backend's Loki API.
 *
 * `php artisan telemetry:deploy` emits an `app.deployment` event — a log line
 * whose body is the event name, with `deployment_id` / `deployment_notes` as
 * structured metadata. Run it from the deploy pipeline and the markers overlay
 * the dashboard's timelines as vertical lines, so a latency or error
 * regression maps to the release that started it.
 *
 * Guarded like the other readers — a missing or unreachable backend returns an
 * empty list, never throws into the CP.
 */
final class Deployments
{
    private const EVENT = 'app.deployment';

    public function __construct(private readonly Backend $backend) {}

    /**
     * Deployment markers in the window, newest first.
     *
     * @return list<array{timestamp: int, id: string, notes: ?string}>
     */
    public function window(int $windowSeconds, int $limit = 50, ?int $endsAt = null): array
    {
        if (! $this->backend->configured()) {
            return [];
        }

        $end = $endsAt ?? time();

        try {
            $response = $this->backend->request()->get($this->backend->endpoint('loki/api/v1/query_range'), [
                'query' => $this->selector().' |= "'.self::EVENT.'"',
                'start' => ($end - $windowSeconds) * 1_000_000_000,
                'end' => $end * 1_000_000_000,
                'limit' => $limit,
                'direction' => 'backward',
            ]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'success') {
            return [];
        }

        return $this->parse($json);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{timestamp: int, id: string, notes: ?string}>
     */
    private function parse(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $result = is_array($data['result'] ?? null) ? $data['result'] : [];

        $rows = [];

        foreach ($result as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            foreach ((is_array($stream['values'] ?? null) ? $stream['values'] : []) as $value) {
                if (! is_array($value) || count($value) < 2 || trim((string) $value[1]) !== self::EVENT) {
                    continue;
                }

                $meta = isset($value[2]) && is_array($value[2]) ? $value[2] : [];
                $id = $meta['deployment_id'] ?? $meta['deployment.id'] ?? null;
                $notes = $meta['deployment_notes'] ?? $meta['deployment.notes'] ?? null;

                $rows[] = [
                    'timestamp' => intdiv((int) $value[0], 1_000_000),
                    'id' => is_string($id) && $id !== '' ? $id : 'unknown',
                    'notes' => is_string($notes) && $notes !== '' ? $notes : null,
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return $rows;
    }

    private function selector(): string
    {
        $selector = config('statamic-telemetry.cp.audit.selector');

        if (is_string($selector) && $selector !== '') {
            return $selector;
        }

        $label = (string) config('statamic-telemetry.cp.audit.service_label', 'service_name');
        $service = (string) (config('statamic-telemetry.cp.audit.service') ?? config('telemetry.service.name', 'laravel'));

        return '{'.$label.'="'.addslashes($service).'"}';
    }
}
