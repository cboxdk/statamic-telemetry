<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\ServiceProvider;
use Illuminate\Http\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Serves the CP stylesheet for the Content audit and Telemetry doctor pages.
 *
 * The CP strips <style> tags from Blade page content, so the styles ship as a
 * real stylesheet linked in the CP <head> ({@see ServiceProvider}).
 * Serving it from the package (rather than publishing into public/) means the
 * pages are styled on install with nothing to publish.
 */
class AssetsController extends CpController
{
    public function stylesheet(): Response
    {
        $css = (string) file_get_contents(__DIR__.'/../../../../resources/css/cp.css');

        return response($css, 200, [
            'Content-Type' => 'text/css',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
