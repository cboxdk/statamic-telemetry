<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Telemetry\TimeRange;
use Illuminate\Http\Request;

it('resolves a look-back window anchored at now', function () {
    $range = TimeRange::fromRequest(Request::create('/t', 'GET', ['window' => '6h']));

    expect($range->window)->toBe('6h')
        ->and($range->seconds)->toBe(6 * 3600)
        ->and($range->endsAt)->toBeNull()
        ->and($range->isCustom())->toBeFalse()
        ->and($range->toProps())->toMatchArray(['window' => '6h', 'from' => null, 'to' => null]);
});

it('prefers a valid brushed from/to over the window, keeping the window for reset', function () {
    $to = time() - 600;
    $from = $to - 1800;

    $range = TimeRange::fromRequest(Request::create('/t', 'GET', ['window' => '24h', 'from' => $from, 'to' => $to]));

    expect($range->isCustom())->toBeTrue()
        ->and($range->seconds)->toBe(1800)
        ->and($range->endsAt)->toBe($to)
        ->and($range->from())->toBe($from)
        ->and($range->window)->toBe('24h')
        ->and($range->toProps())->toMatchArray(['window' => '24h', 'from' => $from, 'to' => $to]);
});

it('rejects a custom range that is too short, too long or in the future', function (array $query) {
    $range = TimeRange::fromRequest(Request::create('/t', 'GET', ['window' => '1h'] + $query));

    expect($range->isCustom())->toBeFalse()->and($range->seconds)->toBe(3600);
})->with([
    'too short' => [['from' => time() - 30, 'to' => time()]],
    'too long' => [['from' => time() - 8 * 86400, 'to' => time()]],
    'in the future' => [['from' => time(), 'to' => time() + 3600]],
    'garbage' => [['from' => 'a', 'to' => 'b']],
]);

it('falls back to the default window for unknown values and parses m/h/d', function () {
    expect(TimeRange::window('bogus')->window)->toBe(TimeRange::DEFAULT)
        ->and(TimeRange::secondsFor('15m'))->toBe(900)
        ->and(TimeRange::secondsFor('7d'))->toBe(7 * 86400)
        ->and(TimeRange::secondsFor('nope'))->toBe(3600);
});
