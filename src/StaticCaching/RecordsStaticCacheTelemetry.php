<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\StaticCaching;

use Illuminate\Http\Request;

/**
 * Overrides shared by the tracing cacher subclasses. invalidateUrls() is
 * deliberately not overridden — AbstractCacher::invalidateUrls() loops
 * invalidateUrl(), which would double-count.
 */
trait RecordsStaticCacheTelemetry
{
    public function getCachedPage(Request $request)
    {
        $page = parent::getCachedPage($request);

        StaticCacheTelemetry::recordHit($request);

        return $page;
    }

    public function hasCachedPage(Request $request)
    {
        $has = parent::hasCachedPage($request);

        if (! $has) {
            StaticCacheTelemetry::recordMiss($request);
        }

        return $has;
    }

    public function invalidateUrl($url, $domain = null)
    {
        parent::invalidateUrl($url, $domain);

        StaticCacheTelemetry::recordInvalidation();
    }

    public function flush()
    {
        parent::flush();

        StaticCacheTelemetry::recordFlush();
    }
}
