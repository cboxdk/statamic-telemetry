<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Listeners;

use Cbox\Telemetry\Support\FailSafe;

/**
 * Base for the addon's event listeners.
 *
 * Unlike the resolver hooks (which laravel-telemetry already wraps in
 * FailSafe), these are plain Laravel event listeners firing inside the
 * dispatch of real operations — an entry save, an asset upload, a login.
 * A throwing listener would break that operation. This base guarantees
 * the addon's core invariant holds here too: telemetry never throws into
 * the application. Subclasses implement handleEvent(); anything it throws
 * is reported and swallowed.
 */
abstract class GuardedListener
{
    public function handle(object $event): void
    {
        FailSafe::guard(fn () => $this->handleEvent($event));
    }

    abstract protected function handleEvent(object $event): void;
}
