<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Diagnostics\Diagnostics;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Telemetry doctor" CP page: a read-only health view of the
 * telemetry pipeline. Thin — {@see Diagnostics} gathers everything.
 */
class DoctorController extends CpController
{
    public function __invoke(Diagnostics $diagnostics): Response
    {
        return Inertia::render('statamic-telemetry::Doctor', [
            'result' => $diagnostics->gather(),
        ]);
    }
}
