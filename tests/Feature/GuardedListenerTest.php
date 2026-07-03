<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Listeners\GuardedListener;

test('a throwing listener never propagates into the dispatched operation', function () {
    $listener = new class extends GuardedListener
    {
        protected function handleEvent(object $event): void
        {
            throw new RuntimeException('telemetry should swallow this');
        }
    };

    // The invariant: no exception escapes handle(), so a Statamic save,
    // upload or login is never broken by the telemetry listener.
    $listener->handle(new stdClass);

    expect(true)->toBeTrue();
});
