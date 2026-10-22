@extends('statamic::layout')
@section('title', 'Trace')

@php
    use Cbox\StatamicTelemetry\Telemetry\TraceSpan;

    $ms = static fn (float $v): string => $v >= 1000 ? round($v / 1000, 2).' s' : round($v, 1).' ms';

    $starts = array_map(static fn (TraceSpan $s): int => $s->startNano, $spans);
    $minStart = $starts === [] ? 0 : min($starts);
    $maxEnd = 0;
    foreach ($spans as $s) {
        $maxEnd = max($maxEnd, $s->startNano + (int) ($s->durationMs * 1_000_000));
    }
    $totalNano = max(1, $maxEnd - $minStart);
    $totalMs = $totalNano / 1_000_000;
@endphp

@section('content')
    <div class="ptel-tele">
        <div class="ptel-tele-head">
            <div>
                <h1>Trace</h1>
                <p class="ptel-sub"><a href="{{ cp_route('telemetry.dashboard') }}">← Back to Telemetry</a> · <code>{{ $traceId }}</code> · {{ $ms($totalMs) }} · {{ count($spans) }} spans</p>
            </div>
        </div>

        @if ($spans === [])
            <div class="ptel-card"><div class="ptel-empty">This trace is no longer in the backend's retention window, or the backend is unreachable.</div></div>
        @else
            <div class="ptel-card ptel-waterfall">
                @foreach ($spans as $span)
                    @php
                        $offset = ($span->startNano - $minStart) / $totalNano * 100;
                        $width = max(0.4, (int) ($span->durationMs * 1_000_000) / $totalNano * 100);
                        $offset = min($offset, 100 - $width);
                    @endphp
                    <div class="ptel-span">
                        <div class="ptel-span-name" style="padding-left: {{ min($span->depth, 10) * 14 }}px">{{ $span->name }}</div>
                        <div class="ptel-span-track">
                            <div class="ptel-span-bar" style="left: {{ round($offset, 2) }}%; width: {{ round($width, 2) }}%"></div>
                        </div>
                        <div class="ptel-span-dur">{{ $ms($span->durationMs) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
