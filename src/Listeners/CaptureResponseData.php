<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\StatamicTelemetry\Support\Content;
use Statamic\Events\ResponseCreated;

/**
 * Stashes the content object Statamic resolved for this response, so the
 * request-naming and enrichment resolvers can read it at terminate.
 */
class CaptureResponseData extends GuardedListener
{
    protected function handleEvent(object $event): void
    {
        if (! $event instanceof ResponseCreated || ! config('statamic-telemetry.instrument.content', true)) {
            return;
        }

        if ($event->data === null || ! app()->bound('request')) {
            return;
        }

        Content::capture(request(), $event->data);
    }
}
