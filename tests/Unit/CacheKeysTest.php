<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Support\CacheKeys;

test('stache keys are grouped into bounded buckets', function (string $key, string $group) {
    expect(CacheKeys::classify('file', $key))->toBe($group);
})->with([
    ['stache::indexes::collections::blog::title', 'stache.index'],
    ['stache::items::collections::blog::abc123', 'stache.item'],
    ['stache::timing', 'stache.meta'],
    ['stache::timestamps::entries', 'stache.meta'],
    ['static-cache:responses:abc', 'static_cache'],
    ['nocache::session.abc', 'static_cache.nocache'],
    ['some-app-key', 'app'],
]);

test('no key is dropped, keeping the counter labelset consistent', function () {
    expect(CacheKeys::classify('redis', 'anything'))->not->toBeNull();
});
