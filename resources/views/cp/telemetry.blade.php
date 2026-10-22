@extends('statamic::layout')
@section('title', 'Telemetry')

@php
    use Cbox\StatamicTelemetry\Telemetry\Svg;

    $ms = static fn (float $v): string => $v >= 1000 ? round($v / 1000, 2).' s' : round($v).' ms';
    $tone = static fn (float $v): string => $v >= 1000 ? 'var(--color-red-500)' : ($v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');
    $maxAvg = max(1.0, ...array_map(static fn (array $e): float => $e['avgMs'], $overview->endpoints ?: [['avgMs' => 1.0]]));
@endphp

@section('content')
    <div class="ptel-tele">
        <div class="ptel-tele-head">
            <div>
                <h1>Telemetry</h1>
                <p class="ptel-sub">Requests to this site — volume, latency and the slowest endpoints, read live from the telemetry backend.</p>
            </div>
            <form method="GET" class="ptel-window">
                <select name="window">
                    @foreach ($windows as $value => $label)
                        <option value="{{ $value }}" @selected($window === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="ptel-btn ptel-btn-primary">Apply</button>
            </form>
        </div>

        @if ($overview->isEmpty())
            <div class="ptel-card"><div class="ptel-empty">No request traces in this window. Make sure the telemetry backend is reachable and receiving traces from this app.</div></div>
        @else
            <div class="ptel-tiles">
                <div class="ptel-tile">
                    <div class="ptel-label">Requests</div>
                    <div class="ptel-value">{{ number_format($overview->count) }}</div>
                    <div class="ptel-detail">{{ $overview->perMinute }}/min</div>
                    <div class="ptel-spark" style="color: var(--color-blue-500)">{!! Svg::area($overview->countSeries, 320, 48) !!}</div>
                </div>
                <div class="ptel-tile">
                    <div class="ptel-label">p50 latency</div>
                    <div class="ptel-value" style="color: {{ $tone($overview->p50) }}">{{ $ms($overview->p50) }}</div>
                    <div class="ptel-detail">median request</div>
                </div>
                <div class="ptel-tile">
                    <div class="ptel-label">p95 latency</div>
                    <div class="ptel-value" style="color: {{ $tone($overview->p95) }}">{{ $ms($overview->p95) }}</div>
                    <div class="ptel-detail">slowest 5%</div>
                </div>
                <div class="ptel-tile">
                    <div class="ptel-label">Max</div>
                    <div class="ptel-value" style="color: {{ $tone($overview->maxMs) }}">{{ $ms($overview->maxMs) }}</div>
                    <div class="ptel-detail">slowest request</div>
                </div>
            </div>

            <div class="ptel-card ptel-chart-card">
                <div class="ptel-chart-head"><span class="ptel-label">Avg latency over time</span></div>
                <div class="ptel-chart-wrap" style="color: var(--color-blue-500)">{!! Svg::area($overview->latencySeries, 960, 140) !!}</div>
            </div>

            <div class="ptel-grid">
                <div class="ptel-card">
                    <div class="ptel-card-head">Slowest endpoints</div>
                    <table>
                        <thead><tr><th>Endpoint</th><th>Calls</th><th>Avg</th><th>Max</th></tr></thead>
                        <tbody>
                            @foreach ($overview->endpoints as $e)
                                <tr>
                                    <td class="ptel-endpoint">{{ $e['name'] }}<div class="ptel-meter-row">{!! Svg::meter($e['avgMs'] / $maxAvg) !!}</div></td>
                                    <td>{{ number_format($e['count']) }}</td>
                                    <td style="color: {{ $tone($e['avgMs']) }}">{{ $ms($e['avgMs']) }}</td>
                                    <td class="ptel-dim">{{ $ms($e['maxMs']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

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
            </div>
        @endif
    </div>
@endsection
