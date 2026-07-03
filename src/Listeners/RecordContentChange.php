<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Str;
use Statamic\Events;

/**
 * One counter for all content mutations, labelled by type and action —
 * the event class name carries both (EntrySaved → entry/saved,
 * AssetReplaced → asset/replaced). Events whose names don't decompose
 * that way carry an explicit mapping. Both dimensions are bounded by
 * the fixed set of events the service provider subscribes to.
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
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function classify(object $event): array
    {
        if (isset(self::SPECIAL[$event::class])) {
            return self::SPECIAL[$event::class];
        }

        if (! preg_match('/^(.+?)('.self::ACTIONS.')$/', class_basename($event), $matches)) {
            return [null, null];
        }

        return [Str::snake($matches[1]), strtolower($matches[2])];
    }
}
