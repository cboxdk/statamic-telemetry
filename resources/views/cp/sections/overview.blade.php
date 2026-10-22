@php
    use Cbox\StatamicTelemetry\Telemetry\Svg;
@endphp

@if ($metrics->requestTotal <= 0 && $overview->isEmpty() && $metrics->routes === [])
    <div class="ptel-card"><div class="ptel-empty">No requests in this window. Make sure the telemetry backend is reachable and receiving traces and metrics from this app.</div></div>
@else
    <div class="ptel-tiles">
        <div class="ptel-tile">
            <div class="ptel-label">Requests</div>
            <div class="ptel-value">{{ number_format($metrics->requestTotal ?: $overview->count) }}</div>
            <div class="ptel-detail">in this window</div>
            @if (! $overview->isEmpty())
                <div class="ptel-spark" style="color: var(--color-blue-500)">{!! Svg::area($overview->countSeries, 320, 48) !!}</div>
            @endif
        </div>
        <div class="ptel-tile">
            <div class="ptel-label">p50 latency</div>
            <div class="ptel-value" style="color: {{ $tone($metrics->p50) }}">{{ $ms($metrics->p50) }}</div>
            <div class="ptel-detail">median (histogram)</div>
        </div>
        <div class="ptel-tile">
            <div class="ptel-label">p95 latency</div>
            <div class="ptel-value" style="color: {{ $tone($metrics->p95) }}">{{ $ms($metrics->p95) }}</div>
            <div class="ptel-detail">slowest 5%</div>
        </div>
        <div class="ptel-tile">
            <div class="ptel-label">p99 latency</div>
            <div class="ptel-value" style="color: {{ $tone($metrics->p99) }}">{{ $ms($metrics->p99) }}</div>
            <div class="ptel-detail">slowest 1%</div>
        </div>
    </div>

    @if (! $overview->isEmpty())
        <div class="ptel-card ptel-chart-card">
            <div class="ptel-chart-head"><span class="ptel-label">Avg latency over time</span></div>
            <div class="ptel-chart-wrap" style="color: var(--color-blue-500)">{!! Svg::area($overview->latencySeries, 960, 140) !!}</div>
        </div>
    @endif

    <div class="ptel-grid">
        @if ($metrics->routes !== [])
            <div class="ptel-card">
                <div class="ptel-card-head">Requests by route</div>
                @php $maxR = max(1.0, ...array_map(static fn (array $r): float => (float) $r['count'], $metrics->routes)); @endphp
                <table>
                    <thead><tr><th>Route</th><th>Requests</th></tr></thead>
                    <tbody>
                        @foreach ($metrics->routes as $r)
                            <tr>
                                <td class="ptel-endpoint">{{ $r['route'] }}<div class="ptel-meter-row">{!! Svg::meter((float) $r['count'] / $maxR) !!}</div></td>
                                <td>{{ number_format((float) $r['count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if (! $overview->isEmpty())
            <div class="ptel-card">
                <div class="ptel-card-head">Recent requests</div>
                <table>
                    <thead><tr><th>When</th><th>Request</th><th>Duration</th></tr></thead>
                    <tbody>
                        @foreach ($overview->recent as $t)
                            @php $at = \Carbon\Carbon::createFromTimestampMs($t->timestampMs); @endphp
                            <tr>
                                <td class="ptel-when">{{ $at->format('H:i:s') }}</td>
                                <td class="ptel-item-title"><a href="{{ cp_route('telemetry.dashboard') }}?trace={{ $t->id }}">{{ $t->name }}</a></td>
                                <td style="color: {{ $tone($t->durationMs) }}">{{ $ms($t->durationMs) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
