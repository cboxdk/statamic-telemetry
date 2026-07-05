<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\StaticCaching;

use Illuminate\Http\Response;
use Statamic\StaticCaching\Replacer;

/**
 * Keeps the browser RUM's per-request bits out of statically cached pages.
 *
 * `{{ telemetry:browser }}` / `{{ telemetry:traceparent }}` emit two things
 * that are unique to the request that rendered them:
 *
 *  - `<meta name="traceparent">` — the id of the *server* span. Baked into
 *    a cached page, every later visitor's RUM would root on one long-gone
 *    server trace.
 *  - `data-session="…"` on the script — the analytics session id. Baked in,
 *    every visitor would share one session.
 *
 * A Statamic Replacer runs before the response is cached (mirroring how
 * CsrfTokenReplacer swaps the CSRF token), so this strips both from the
 * cached copy. The RUM SDK then **self-roots** its trace (no parent) and
 * falls back to its own session id — the correct behaviour for a cache hit,
 * where the request that rendered the page is not this visitor's.
 *
 * The strip applies to the cached copy only; the first (cache-warming)
 * visitor keeps their traceparent — they have a real server span to root
 * on. This covers both cachers:
 *
 *  - **half measure** (ApplicationCacher): the cached copy is served through
 *    PHP but from an old request, so its traceparent must go.
 *  - **full measure** (FileCacher): cache hits are served straight off disk
 *    and never reach PHP, so no server span exists at all — the RUM
 *    necessarily self-roots.
 */
class BrowserTracingReplacer implements Replacer
{
    /**
     * @param  Response  $response  the copy about to be cached
     * @param  Response  $initial  the first visitor's response (left intact)
     */
    public function prepareResponseToCache(Response $response, Response $initial)
    {
        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return;
        }

        $response->setContent($this->strip($content));
    }

    /**
     * Nothing to do on serve: the per-request bits were removed before
     * caching and are deliberately not re-injected — a cached hit has no
     * server span for the browser to parent onto.
     */
    public function replaceInCachedResponse(Response $response)
    {
        //
    }

    private function strip(string $html): string
    {
        // The traceparent meta (from either tag / the Blade directive).
        $html = preg_replace('#<meta\s+name="traceparent"\s+content="[^"]*"\s*/?>#i', '', $html) ?? $html;

        // The per-request analytics session on the RUM script tag.
        $html = preg_replace('#\s+data-session="[^"]*"#i', '', $html) ?? $html;

        return $html;
    }
}
