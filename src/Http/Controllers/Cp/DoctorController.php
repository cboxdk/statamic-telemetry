<?php

declare(strict_types=1);

namespace Cbox\StatamicTelemetry\Http\Controllers\Cp;

use Cbox\StatamicTelemetry\Diagnostics\Diagnostics;
use Illuminate\Contracts\View\View;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The native "Telemetry doctor" CP page: a read-only health view of the
 * telemetry pipeline. Thin — {@see Diagnostics} gathers everything.
 */
class DoctorController extends CpController
{
    public function __invoke(Diagnostics $diagnostics): View
    {
        $data = ['result' => $diagnostics->gather()];

        return view('statamic-telemetry::cp.doctor', $data); // @phpstan-ignore argument.type
    }
}
