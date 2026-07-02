<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\StatamicTelemetry\Support\Content;
use Statamic\Events\ResponseCreated;

/**
 * Stashes the content object Statamic resolved for this response, so the
 * request-naming and enrichment resolvers can read it at terminate.
 */
class CaptureResponseData
{
    public function handle(ResponseCreated $event): void
    {
        if (! config('statamic-telemetry.instrument.content', true)) {
            return;
        }

        if ($event->data === null || ! app()->bound('request')) {
            return;
        }

        Content::capture(request(), $event->data);
    }
}
