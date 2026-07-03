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

        // A successful return is a genuine hit: Statamic only calls
        // getCachedPage after a truthy hasCachedPage, and both cachers
        // throw (FileCacher's File::get on a missing file) rather than
        // return on a real miss. Synthetic probe requests are filtered by
        // isCurrentRequest inside recordHit.
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
