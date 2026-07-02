<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Support;

/**
 * Buckets Statamic's cache traffic into bounded groups for the
 * classifyCacheKeysUsing() hook. The Stache alone produces thousands of
 * raw keys per warm; without grouping, cache spans and counters drown.
 *
 * Everything keeps a group (nothing returns null) so the key_group
 * counter labelset stays consistent across all recorded operations.
 */
final class CacheKeys
{
    public static function classify(string $store, string $key): string
    {
        return match (true) {
            str_starts_with($key, 'stache::indexes::') => 'stache.index',
            str_starts_with($key, 'stache::items::') => 'stache.item',
            str_starts_with($key, 'stache::') => 'stache.meta',
            str_starts_with($key, 'static-cache:') => 'static_cache',
            str_starts_with($key, 'nocache::') => 'static_cache.nocache',
            default => 'app',
        };
    }
}
