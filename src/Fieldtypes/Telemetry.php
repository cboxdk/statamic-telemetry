<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Fieldtypes;

use Cbox\StatamicTelemetry\Http\Controllers\Cp\EntryController;
use Statamic\Entries\Entry;
use Statamic\Fields\Fieldtype;

/**
 * The read-only "Telemetry" tab in an entry's publish form: that entry's own
 * telemetry story — requests, latency against the site, status mix, static
 * cache, exceptions on its path, change history — with every request
 * openable as its trace. It stores nothing. {@see preload()} hands the Vue
 * component the entry and the JSON endpoint ({@see EntryController});
 * the component fetches the story itself, so the editor opens without
 * waiting on the telemetry backend.
 */
class Telemetry extends Fieldtype
{
    protected $icon = 'chart-monitoring-indicator';

    /**
     * @return array<string, mixed>
     */
    public function preload()
    {
        $parent = $this->field()?->parent();

        if (! $parent instanceof Entry || $parent->id() === null) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'entry' => [
                'id' => (string) $parent->id(),
                'title' => (string) $parent->value('title'),
                'uri' => $parent->uri(),
                'url' => $parent->absoluteUrl(),
                'collection' => $parent->collectionHandle(),
            ],
            'endpoint' => cp_route('telemetry.entry', ['id' => $parent->id()]),
            'requestsUrl' => cp_route('telemetry.page', ['page' => 'requests']),
            'windows' => ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'],
            'default' => '7d',
        ];
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
