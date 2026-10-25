<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Telemetry;

/**
 * One row in the traces list: a request, its name, duration, when — and the
 * root span's request/Statamic attributes (path, route, status, collection,
 * static-cache result, …) so a list can say *which page* was hit, not just
 * which route template.
 */
final class TraceSummary
{
    /** Root-span attribute keys worth carrying into a list row (plus every `statamic.*`). */
    public const KEEP = ['http.route', 'http.request.method', 'http.response.status_code', 'url.path'];

    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $service,
        public readonly float $durationMs,
        public readonly int $timestampMs,
        public readonly array $attributes = [],
    ) {}

    public function attr(string $key): ?string
    {
        $value = $this->attributes[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The request path — the thing a person recognises — falling back to the span name. */
    public function path(): string
    {
        return $this->attr('url.path') ?? $this->name;
    }

    /**
     * The row as the CP lists render it.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'service' => $this->service,
            'durationMs' => $this->durationMs,
            'timestamp' => $this->timestampMs,
            'method' => $this->attr('http.request.method'),
            'path' => $this->attr('url.path'),
            'route' => $this->attr('http.route'),
            'status' => $this->attr('http.response.status_code'),
            'type' => $this->attr('statamic.type'),
            'collection' => $this->attr('statamic.collection'),
            'taxonomy' => $this->attr('statamic.taxonomy'),
            'site' => $this->attr('statamic.site'),
            'cache' => $this->attr('statamic.static_cache'),
            'title' => $this->attr('statamic.content.title'),
            'editUrl' => $this->attr('statamic.content.edit_url'),
        ];
    }
}
