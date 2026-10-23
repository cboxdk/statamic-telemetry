<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Fieldtypes;

use Cbox\StatamicTelemetry\Telemetry\EntryTelemetry;
use Statamic\Entries\Entry;
use Statamic\Fields\Fieldtype;

/**
 * A read-only "Telemetry" fieldtype for entry blueprints: it shows that
 * entry's own telemetry inline in the publish form — how often it was
 * requested and how slow (from traces keyed by `statamic.entry.id`) and its
 * change history (from the audit log). It stores nothing; the data is a
 * snapshot gathered in {@see preload()} and rendered by the Vue component.
 */
class Telemetry extends Fieldtype
{
    protected $icon = 'chart-monitoring-indicator';

    /**
     * Gather the entry's telemetry for the Vue component. The field's parent
     * is the entry being edited.
     *
     * @return array<string, mixed>
     */
    public function preload()
    {
        $parent = $this->field()?->parent();

        if (! $parent instanceof Entry) {
            return ['available' => false];
        }

        return ['available' => true] + app(EntryTelemetry::class)->for((string) $parent->id());
    }

    public function preProcess($data)
    {
        return null;
    }

    public function process($data)
    {
        return null;
    }

    public function augment($value)
    {
        return null;
    }
}
