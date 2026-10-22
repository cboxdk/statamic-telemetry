@extends('statamic::layout')
@section('title', 'Content audit')

@php
    /** Action transition → a status colour, as a CP theme token. */
    $tone = static function (string $action): string {
        return match ($action) {
            'created', 'published', 'uploaded', 'publish' => 'var(--color-green-500)',
            'updated' => 'var(--color-blue-500)',
            'unpublished', 'unpublish', 'replaced', 'reuploaded', 'restore' => 'var(--color-orange-500)',
            'deleted' => 'var(--color-red-500)',
            default => 'var(--color-gray-500)',
        };
    };

    // Keep the active selection choosable even if it has no rows this window.
    $withActive = static function (array $options, string $active): array {
        if ($active !== '' && ! in_array($active, $options, true)) {
            $options[] = $active;
            sort($options);
        }

        return $options;
    };

    $host = request()->getHost();
@endphp

@section('content')
    <div class="ptel-audit">
        <div class="ptel-head">
            <div>
                <h1>Content audit</h1>
                <p class="ptel-sub">Every change to content and structure — who made it, what it touched, and what it did.</p>
            </div>
        </div>

        <div class="ptel-card">
            <form method="GET" class="ptel-filters">
                <div class="ptel-field">
                    <label for="ptel-type">Type</label>
                    <select name="type" id="ptel-type">
                        <option value="">All types</option>
                        @foreach ($withActive($result->types, $query->type) as $option)
                            <option value="{{ $option }}" @selected($query->type === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ptel-field">
                    <label for="ptel-action">Action</label>
                    <select name="action" id="ptel-action">
                        <option value="">All actions</option>
                        @foreach ($withActive($result->actions, $query->action) as $option)
                            <option value="{{ $option }}" @selected($query->action === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>

                @if (count($result->sites) > 1 || $query->site !== '')
                    <div class="ptel-field">
                        <label for="ptel-site">Site</label>
                        <select name="site" id="ptel-site">
                            <option value="">All sites</option>
                            @foreach ($withActive($result->sites, $query->site) as $option)
                                <option value="{{ $option }}" @selected($query->site === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="ptel-field">
                    <label for="ptel-window">Window</label>
                    <select name="window" id="ptel-window">
                        @foreach ($windows as $value => $label)
                            <option value="{{ $value }}" @selected($query->window === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ptel-field ptel-grow">
                    <label for="ptel-q">Search</label>
                    <input type="search" name="q" id="ptel-q" value="{{ $query->search }}" placeholder="Title, id, editor, field…">
                </div>

                <button type="submit" class="ptel-btn ptel-btn-primary">Filter</button>
            </form>

            @if ($result->error)
                <div class="ptel-banner">{{ $result->error }}</div>
            @endif

            @if ($result->isEmpty())
                <div class="ptel-empty">
                    @if ($result->error)
                        Could not load the audit log.
                    @elseif ($query->hasFilters())
                        No changes match these filters in this period.
                    @else
                        No content changes in this period. The audit log needs content events writing to the telemetry backend.
                    @endif
                </div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Action</th>
                            <th>Item</th>
                            <th>Changed</th>
                            <th>Who</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result->rows as $row)
                            @php
                                $at = \Carbon\Carbon::createFromTimestampMs($row->timestamp);
                                $newTab = $row->editUrl !== null && parse_url($row->editUrl, PHP_URL_HOST) !== null && parse_url($row->editUrl, PHP_URL_HOST) !== $host;
                            @endphp
                            <tr>
                                <td class="ptel-when" title="{{ $at->toDateTimeString() }}">{{ $at->format('d/m H:i:s') }}</td>
                                <td><span class="ptel-badge" style="--tone: {{ $tone($row->action) }}">{{ $row->action }}</span></td>
                                <td>
                                    <div class="ptel-item-title">
                                        @if ($row->editUrl)
                                            <a href="{{ $row->editUrl }}" @if ($newTab) target="_blank" rel="noopener" @endif>{{ $row->label() }}</a>
                                        @else
                                            {{ $row->label() }}
                                        @endif
                                    </div>
                                    @if ($context = $row->context())
                                        <div class="ptel-meta">{{ $context }}@if ($row->message) — “{{ $row->message }}”@endif</div>
                                    @endif
                                </td>
                                <td class="ptel-changed">{{ $row->changed ?? '—' }}</td>
                                <td>
                                    <div>{{ $row->who ?? $row->origin ?? '—' }}</div>
                                    @if ($row->who && $row->origin)
                                        <div class="ptel-meta">{{ $row->origin }}</div>
                                    @elseif ($row->command)
                                        <div class="ptel-meta">{{ $row->command }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="ptel-foot">
                    <div>{{ number_format($result->total) }} {{ \Illuminate\Support\Str::plural('change', $result->total) }}</div>
                    @if ($result->totalPages() > 1)
                        <div class="ptel-pages">
                            @if ($result->page > 1)
                                <a href="{{ request()->fullUrlWithQuery(['page' => $result->page - 1]) }}" rel="prev">Previous</a>
                            @else
                                <span>Previous</span>
                            @endif
                            <span>Page {{ $result->page }} of {{ $result->totalPages() }}</span>
                            @if ($result->page < $result->totalPages())
                                <a href="{{ request()->fullUrlWithQuery(['page' => $result->page + 1]) }}" rel="next">Next</a>
                            @else
                                <span>Next</span>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($result->capped)
                    <div class="ptel-note">Showing the newest {{ number_format((int) config('statamic-telemetry.cp.audit.limit', 1000)) }} changes in this window — narrow the window to see further back.</div>
                @endif
            @endif
        </div>
    </div>
@endsection
