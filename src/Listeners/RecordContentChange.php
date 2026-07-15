<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Str;
use Statamic\Entries\Entry;
use Statamic\Events;

/**
 * One counter for all content mutations, labelled by type and action —
 * the event class name carries both (EntrySaved → entry/saved,
 * AssetReplaced → asset/replaced). Events whose names don't decompose
 * that way carry an explicit mapping. Entry saves are labelled by the
 * entry's publish status (published/draft/scheduled/expired) rather than
 * a flat "saved", so the counter shows the publish-state mix of editing
 * activity. Both dimensions are bounded by the fixed set of events the
 * service provider subscribes to.
 */
class RecordContentChange extends GuardedListener
{
    private const ACTIONS = 'Saved|Deleted|Uploaded|Replaced|Reuploaded|Updated';

    private const SPECIAL = [
        Events\EntryScheduleReached::class => ['entry', 'schedule_reached'],
        Events\DuplicateIdRegenerated::class => ['duplicate_id', 'regenerated'],
    ];

    protected function handleEvent(object $event): void
    {
        if (! config('statamic-telemetry.instrument.content_events', true)) {
            return;
        }

        [$type, $action] = $this->classify($event);

        if ($type === null) {
            return;
        }

        Telemetry::counter('statamic.content.changes', 'Content changes by type and action')
            ->inc(1, ['type' => $type, 'action' => $action]);

        // A child span so each content mutation is an individual, drillable row
        // correlated to the CP request (or command) that made it.
        Telemetry::tracer()->recordSpan('statamic.content.change', 0.0, [
            'statamic.content.type' => $type,
            'statamic.content.action' => $action,
        ]);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function classify(object $event): array
    {
        // An entry save is refined into a publish-lifecycle action.
        if ($event instanceof Events\EntrySaved && $event->entry instanceof Entry) {
            return ['entry', $this->entrySaveAction($event->entry)];
        }

        if (isset(self::SPECIAL[$event::class])) {
            return self::SPECIAL[$event::class];
        }

        if (! preg_match('/^(.+?)('.self::ACTIONS.')$/', class_basename($event), $matches)) {
            return [null, null];
        }

        return [Str::snake($matches[1]), strtolower($matches[2])];
    }

    /**
     * The entry's publish status at save time — `published` / `draft` /
     * `scheduled` / `expired`. A status *snapshot*, not a transition:
     * detecting an actual publish/unpublish would need the pre-save
     * published value, which Statamic has already synced away by the time
     * the save event fires. `status()` is reliable, so this is: "an entry
     * in <status> was saved" — the publish-state mix of editing activity.
     */
    private function entrySaveAction(Entry $entry): string
    {
        $status = $entry->status();

        return in_array($status, ['published', 'draft', 'scheduled', 'expired'], true)
            ? $status
            : 'saved';
    }
}
