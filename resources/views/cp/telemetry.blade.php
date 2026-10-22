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

        @if ($metrics->hasData())
            <h2 class="ptel-section">Metrics</h2>
            <div class="ptel-grid">
                <div class="ptel-card">
                    <div class="ptel-card-head">Requests by route</div>
                    @php $maxR = max(1.0, ...array_map(static fn (array $r): float => $r['count'], $metrics->routes ?: [['count' => 1.0]])); @endphp
                    <table>
                        <thead><tr><th>Route</th><th>Requests</th></tr></thead>
                        <tbody>
                            @foreach ($metrics->routes as $r)
                                <tr>
                                    <td class="ptel-endpoint">{{ $r['route'] }}<div class="ptel-meter-row">{!! Svg::meter($r['count'] / $maxR) !!}</div></td>
                                    <td>{{ number_format($r['count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div>
                    @if ($metrics->cacheOperations !== [])
                        <div class="ptel-card" style="margin-bottom: 1rem">
                            <div class="ptel-card-head">Static cache</div>
                            <div class="ptel-pad">
                                @if ($metrics->cacheHitRatio !== null)
                                    @php $hr = $metrics->cacheHitRatio; @endphp
                                    <div class="ptel-value" style="color: {{ $hr >= 0.8 ? 'var(--color-green-500)' : ($hr >= 0.4 ? 'var(--color-orange-500)' : 'var(--color-red-500)') }}">{{ round($hr * 100) }}%</div>
                                    <div class="ptel-detail">hit ratio</div>
                                @endif
                                <div class="ptel-ops">
                                    @foreach ($metrics->cacheOperations as $op => $n)
                                        <span class="ptel-op"><span class="ptel-op-k">{{ $op }}</span>{{ number_format($n) }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($metrics->contentChanges !== [])
                        <div class="ptel-card">
                            <div class="ptel-card-head">Content changes</div>
                            <table>
                                <thead><tr><th>Type</th><th>Action</th><th>Count</th></tr></thead>
                                <tbody>
                                    @foreach ($metrics->contentChanges as $c)
                                        <tr>
                                            <td>{{ $c['type'] }}</td>
                                            <td><span class="ptel-badge" style="--tone: var(--color-blue-500)">{{ $c['action'] }}</span></td>
                                            <td>{{ number_format($c['count']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endsection
