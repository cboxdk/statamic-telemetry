@php
    $glideTotal = array_sum(array_map(static fn (array $g): float => (float) $g['count'], $metrics->glide));
    $formsTotal = array_sum(array_map(static fn (array $f): float => (float) $f['count'], $metrics->forms));
    $changesTotal = array_sum(array_map(static fn (array $c): float => $c['count'], $metrics->contentChanges));
    $ms = static fn (float $v): string => $v <= 0 ? '—' : ($v >= 1000 ? round($v / 1000, 2).' s' : round($v).' ms');
@endphp

@if (! $metrics->hasStatamic())
    <div class="ptel-card"><div class="ptel-empty">No Statamic metrics in this window yet. They need a shared <code>TELEMETRY_STORE</code> and <code>telemetry:flush</code> shipping them to the backend; the counters fill as the cache, Stache, Glide, forms and content are used.</div></div>
@else
    <div class="ptel-tiles">
        <div class="ptel-tile">
            <div class="ptel-label">Static cache hit ratio</div>
            @php $hr = $metrics->cacheHitRatio; @endphp
            <div class="ptel-value" style="color: {{ $hr === null ? 'var(--color-gray-500)' : ($hr >= 0.8 ? 'var(--color-green-500)' : ($hr >= 0.4 ? 'var(--color-orange-500)' : 'var(--color-red-500)')) }}">{{ $hr === null ? '—' : round($hr * 100).'%' }}</div>
            <div class="ptel-detail">served from cache</div>
        </div>
        <div class="ptel-tile">
            <div class="ptel-label">Stache warms</div>
            <div class="ptel-value">{{ number_format($metrics->stacheWarms) }}</div>
            <div class="ptel-detail">p95 warm {{ $ms($metrics->stacheWarmP95 ?? 0) }}</div>
        </div>
        <div class="ptel-tile">
            <div class="ptel-label">Glide generations</div>
            <div class="ptel-value">{{ number_format($glideTotal) }}</div>
            <div class="ptel-detail">{{ number_format($metrics->glideClears) }} cache clears</div>
        </div>
        <div class="ptel-tile">
            <div class="ptel-label">Form submissions</div>
            <div class="ptel-value">{{ number_format($formsTotal) }}</div>
            <div class="ptel-detail">{{ number_format($changesTotal) }} content changes</div>
        </div>
    </div>

    <div class="ptel-grid">
        @if ($metrics->cacheOperations !== [])
            <div class="ptel-card">
                <div class="ptel-card-head">Static cache operations</div>
                <div class="ptel-pad">
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

        @if ($metrics->glide !== [])
            <div class="ptel-card">
                <div class="ptel-card-head">Glide by preset</div>
                <table>
                    <thead><tr><th>Preset</th><th>Images</th></tr></thead>
                    <tbody>
                        @foreach ($metrics->glide as $g)
                            <tr><td>{{ $g['preset'] }}</td><td>{{ number_format((float) $g['count']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($metrics->forms !== [])
            <div class="ptel-card">
                <div class="ptel-card-head">Form submissions</div>
                <table>
                    <thead><tr><th>Form</th><th>Submissions</th></tr></thead>
                    <tbody>
                        @foreach ($metrics->forms as $f)
                            <tr><td>{{ $f['form'] }}</td><td>{{ number_format((float) $f['count']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
