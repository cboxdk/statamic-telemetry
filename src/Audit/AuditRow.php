<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Audit;

use Cbox\StatamicTelemetry\Support\ContentAudit;

/**
 * One content-change row, read back from the logs backend.
 *
 * The audit trail is written out as `statamic.content.change` events
 * ({@see ContentAudit}); this is the same
 * record seen from the other side — a Loki log line whose body is the event
 * name and whose OTLP attributes arrive as structured metadata. OTLP promotes
 * attributes to Loki either as stream labels or per-line structured metadata,
 * and Loki sanitises dots to underscores on the metadata keys, so every lookup
 * tries both `statamic.content.type` and `statamic_content_type`.
 */
final class AuditRow
{
    /**
     * @param  int  $timestamp  milliseconds since the epoch
     * @param  list<string>  $searchTerms  the free-text haystack, lower-cased on demand
     */
    private function __construct(
        public readonly int $timestamp,
        public readonly string $type,
        public readonly string $action,
        public readonly ?string $id,
        public readonly ?string $title,
        public readonly ?string $handle,
        public readonly ?string $scope,
        public readonly ?string $site,
        public readonly ?string $changed,
        public readonly ?string $message,
        public readonly ?string $editUrl,
        public readonly ?string $who,
        public readonly ?string $userId,
        public readonly ?string $origin,
        public readonly ?string $command,
        private readonly array $searchTerms,
    ) {}

    /**
     * Build a row from a Loki entry's timestamp (nanoseconds) and its merged
     * stream labels + structured metadata.
     *
     * @param  array<string, string>  $labels
     */
    public static function fromLabels(int $timestampNano, array $labels): self
    {
        $get = static function (string $key) use ($labels): ?string {
            $value = $labels[$key] ?? $labels[str_replace('.', '_', $key)] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        $userId = $get('user.id');
        $who = $get('user.name') ?? $userId;
        $edit = $get('statamic.content.edit_url');

        $id = $get('statamic.content.id');
        $title = $get('statamic.content.title');
        $handle = $get('statamic.content.handle');
        $scope = $get('statamic.collection')
            ?? $get('statamic.taxonomy')
            ?? $get('statamic.asset.container')
            ?? $get('statamic.form')
            ?? $get('statamic.blueprint');
        $changed = $get('statamic.content.changed');
        $message = $get('statamic.revision.message');

        return new self(
            timestamp: intdiv($timestampNano, 1_000_000),
            type: $get('statamic.content.type') ?? 'unknown',
            action: $get('statamic.content.action') ?? 'changed',
            id: $id,
            title: $title,
            handle: $handle,
            scope: $scope,
            site: $get('statamic.site'),
            changed: $changed,
            message: $message,
            // Only a web link is trusted: this came back from the backend, not
            // from us, so never follow a non-http(s) value into the CP.
            editUrl: $edit !== null && preg_match('#^https?://#i', $edit) === 1 ? $edit : null,
            who: $who,
            userId: $userId,
            origin: $get('statamic.content.origin'),
            command: $get('process.command'),
            searchTerms: array_values(array_filter([
                $title, $id, $handle, $scope, $who, $changed, $message,
                $get('statamic.content.url'), $get('statamic.content.path'),
            ], static fn (?string $term): bool => $term !== null)),
        );
    }

    /** The label and short title an editor recognises. */
    public function label(): string
    {
        return $this->title ?? $this->handle ?? $this->id ?? '—';
    }

    /** Type · scope · site — the one-line context under the item. */
    public function context(): string
    {
        return implode(' · ', array_filter(
            [$this->type, $this->scope, $this->site],
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));
    }

    /** Does the row match a lower-cased free-text needle? */
    public function matches(string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        foreach ($this->searchTerms as $term) {
            if (str_contains(mb_strtolower($term), $needle)) {
                return true;
            }
        }

        return false;
    }
}
