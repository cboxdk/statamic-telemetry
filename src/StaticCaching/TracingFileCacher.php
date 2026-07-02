<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\StaticCaching;

use Illuminate\Http\Request;
use Statamic\StaticCaching\Cachers\FileCacher;

/**
 * FileCacher with telemetry. Full-measure hits served by the web server
 * never reach PHP and are invisible here — only PHP-served hits (before
 * the rewrite rule kicks in), misses and writes are recorded.
 */
final class TracingFileCacher extends FileCacher
{
    use RecordsStaticCacheTelemetry;

    public function cachePage(Request $request, $content)
    {
        parent::cachePage($request, $content);

        StaticCacheTelemetry::recordWrite($request);
    }
}
