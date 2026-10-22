@extends('statamic::layout')
@section('title', 'Telemetry doctor')

@php
    /** tone → CP theme colour token. */
    $color = static fn (string $tone): string => match ($tone) {
        'ok' => 'var(--color-green-500)',
        'warn' => 'var(--color-orange-500)',
        'danger' => 'var(--color-red-500)',
        default => 'var(--color-gray-500)',
    };

    $yesNo = static fn (bool $on): string => $on ? 'enabled' : 'disabled';

    // Spool tone/summary: an error reading it is danger, a non-empty queue is
    // a warning (events waiting to ship), empty/drained is good.
    if ($result->spoolError !== null) {
        $spoolTone = 'danger';
        $spoolValue = 'unavailable';
    } elseif (! $result->spoolEnabled) {
        $spoolTone = 'dim';
        $spoolValue = 'direct (no spool)';
    } elseif (($result->spoolSize ?? 0) > 0) {
        $spoolTone = 'warn';
        $spoolValue = number_format((int) $result->spoolSize).' pending';
    } else {
        $spoolTone = 'ok';
        $spoolValue = 'drained (0 pending)';
    }

    $backendTone = $result->backendReachable === true ? 'ok' : ($result->backendReachable === false ? 'danger' : 'dim');
    $backendValue = $result->backendReachable === true ? 'reachable' : ($result->backendReachable === false ? 'unreachable' : 'not configured');

    $backendHost = $result->backendUrl ? (parse_url($result->backendUrl, PHP_URL_HOST) ?: $result->backendUrl) : null;

    // The detail rows under the two headline tiles.
    $rows = [
        ['Telemetry', $yesNo($result->telemetryEnabled), $result->telemetryEnabled ? 'ok' : 'danger', 'The base laravel-telemetry master switch.'],
        ['Statamic overlay', $yesNo($result->overlayEnabled), $result->overlayEnabled ? 'ok' : 'danger', 'This addon (statamic-telemetry.enabled).'],
        ['Service', $result->serviceName, 'dim', 'service.name stamped on every signal.'],
        ['Metric store', $result->storeDriver, 'dim', 'Where counters/gauges live for the Prometheus scrape.'],
        ['OTLP endpoint', $result->otlpEndpoint !== '' ? $result->otlpEndpoint : '—', 'dim', 'Where traces and events ship.'],
        ['Exporters', $result->exporters === [] ? 'none' : implode(', ', $result->exporters), 'dim', 'Extra exporters beyond the default OTLP transport.'],
    ];
@endphp

@section('content')
    <div class="ptel-doctor">
        <h1>Telemetry doctor</h1>
        <p class="ptel-sub">The telemetry pipeline at a glance — what is on, where it ships, how deep the spool is, and whether the audit log's backend answers.</p>

        <div class="ptel-tiles">
            <div class="ptel-tile">
                <div class="ptel-label">Spool</div>
                <div class="ptel-value"><span class="ptel-dot" style="--tone: {{ $color($spoolTone) }}"></span>{{ $spoolValue }}</div>
                <div class="ptel-detail">
                    @if ($result->spoolError !== null)
                        Could not read the spool: {{ $result->spoolError }}
                    @elseif ($result->spoolEnabled)
                        OTLP spool ({{ $result->spoolDriver }}) — entries buffered until <code>telemetry:flush</code> ships them.
                    @else
                        Spans and events POST at request terminate; no queue to drain.
                    @endif
                </div>
            </div>

            <div class="ptel-tile">
                <div class="ptel-label">Logs backend</div>
                <div class="ptel-value"><span class="ptel-dot" style="--tone: {{ $color($backendTone) }}"></span>{{ $backendValue }}</div>
                <div class="ptel-detail">
                    {{ $result->backendDetail }}
                    @if ($backendHost)
                        <br>{{ $backendHost }}{{ $result->hasToken ? ' · token set' : '' }}{{ $result->hasTenant ? ' · tenant set' : '' }}
                    @endif
                </div>
            </div>
        </div>

        <div class="ptel-card">
            @foreach ($rows as [$label, $value, $tone, $note])
                <div class="ptel-row">
                    <div class="ptel-k"><span class="ptel-dot" style="--tone: {{ $color($tone) }}"></span>{{ $label }}</div>
                    <div>
                        <div class="ptel-v">{{ $value }}</div>
                        <div class="ptel-note">{{ $note }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="ptel-hint">For the full end-to-end check — store round trip, series budget, redaction, Prometheus and OTLP reachability — run <code>php artisan telemetry:doctor</code>.</p>
    </div>
@endsection
