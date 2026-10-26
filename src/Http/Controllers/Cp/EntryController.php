<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Telemetry\EntryTelemetry;
use Cbox\StatamicTelemetry\Telemetry\TimeRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Entries\Entry as EntryModel;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/**
 * One entry's telemetry story as JSON, for the Telemetry tab in the entry
 * editor. The tab fetches it on open and again when its window changes, so
 * the publish form itself stays light. A week by default — an editor's
 * question is "how is this page doing", not "this hour".
 */
class EntryController extends CpController
{
    public function __invoke(Request $request, string $id, EntryTelemetry $telemetry): JsonResponse
    {
        $entry = Entry::find($id);

        abort_unless($entry instanceof EntryModel, 404);

        // Telemetry about an entry is as private as the entry: the same `view` the editor needs.
        $this->authorize('view', $entry);

        $range = $request->has('window') || $request->has('from') ? TimeRange::fromRequest($request) : TimeRange::window('7d');

        return response()->json([
            'entry' => [
                'id' => (string) $entry->id(),
                'title' => (string) $entry->value('title'),
                'uri' => $entry->uri(),
                'url' => $entry->absoluteUrl(),
                'editUrl' => $entry->editUrl(),
                'collection' => $entry->collectionHandle(),
            ],
            ...$range->toProps(),
            ...$telemetry->story((string) $entry->id(), $entry->uri(), $range),
        ]);
    }
}
