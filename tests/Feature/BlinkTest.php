<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Support\TracingBlink;
use Cbox\Telemetry\Facades\Telemetry;
use Statamic\Facades\Blink;

test('the tallying blink is bound', function () {
    expect(app(Statamic\Support\Blink::class))->toBeInstanceOf(TracingBlink::class);
});

test('blink once hits and misses land as tallies on the root span', function () {
    $fake = $this->fakeTelemetry();

    $span = Telemetry::span('GET /page');

    Blink::once('expensive', fn () => 'computed');   // miss
    Blink::once('expensive', fn () => 'computed');   // hit
    Blink::once('expensive', fn () => 'computed');   // hit

    $span->end();
    $fake->flush();

    $fake->assertSpanRecorded('GET /page', function ($span) {
        return $span->attributes()['statamic.blink.misses'] === 1
            && $span->attributes()['statamic.blink.hits'] === 2;
    });
});

test('blink still memoizes correctly through the tallying store', function () {
    $calls = 0;

    $first = Blink::once('memo', function () use (&$calls) {
        $calls++;

        return 'value';
    });

    $second = Blink::once('memo', function () use (&$calls) {
        $calls++;

        return 'other';
    });

    expect($first)->toBe('value')
        ->and($second)->toBe('value')
        ->and($calls)->toBe(1);
});
