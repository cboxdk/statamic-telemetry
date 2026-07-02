<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Support;

use Statamic\Support\Blink;

/**
 * Statamic's Blink is the per-request memoization layer — augmentation
 * caching, repeated entry lookups, config reads. It fires no events, so
 * without this it is invisible. Every store this wrapper hands out
 * tallies once() hits and misses onto the trace root span
 * (statamic.blink.hits / statamic.blink.misses) — the memoization
 * effectiveness of the request, not per-key noise.
 */
class TracingBlink extends Blink
{
    public function store($name = 'default')
    {
        return $this->stores[$name] ??= new TallyingBlinkStore;
    }
}
