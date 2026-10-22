@extends('statamic::layout')
@section('title', 'Telemetry')

@php
    use Cbox\StatamicTelemetry\Telemetry\Svg;

    $ms = static fn (float $v): string => $v <= 0 ? '—' : ($v >= 1000 ? round($v / 1000, 2).' s' : round($v).' ms');
    $tone = static fn (float $v): string => $v >= 1000 ? 'var(--color-red-500)' : ($v >= 300 ? 'var(--color-orange-500)' : 'var(--color-green-500)');
    $tabUrl = static fn (string $s): string => cp_route('telemetry.dashboard').'?section='.$s.'&window='.$window;
@endphp

@section('content')
    <div class="ptel-tele">
        <div class="ptel-tele-head">
            <div>
                <h1>Telemetry</h1>
                <p class="ptel-sub">This site's telemetry, read live from the backend — requests and traces, and the Statamic signals this addon emits.</p>
            </div>
            <form method="GET" class="ptel-window">
                <input type="hidden" name="section" value="{{ $section }}">
                <select name="window">
                    @foreach ($windows as $value => $label)
                        <option value="{{ $value }}" @selected($window === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="ptel-btn ptel-btn-primary">Apply</button>
            </form>
        </div>

        <nav class="ptel-tabs">
            @foreach ($sections as $value => $label)
                <a href="{{ $tabUrl($value) }}" class="ptel-tab @if($section === $value) ptel-tab-active @endif">{{ $label }}</a>
            @endforeach
        </nav>

        @if ($section === 'overview')
            @include('statamic-telemetry::cp.sections.overview')
        @else
            @include('statamic-telemetry::cp.sections.statamic')
        @endif
    </div>
@endsection
