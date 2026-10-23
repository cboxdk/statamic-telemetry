<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Http\Controllers\Cp\AuditController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\DoctorController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\ExploreController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\TelemetryController;
use Illuminate\Support\Facades\Route;

// The native Telemetry dashboard (Requests + single-trace waterfall).
Route::get('telemetry', TelemetryController::class)
    ->middleware('can:view telemetry')
    ->name('telemetry.dashboard');

// Explore — two-pane requests / traces / logs / errors.
Route::get('telemetry/explore', ExploreController::class)
    ->middleware('can:view telemetry')
    ->name('telemetry.explore');

// Runs inside Statamic's authenticated CP route group (name prefix
// `statamic.cp.`, so these resolve as `cp_route('telemetry.audit')` etc.); the
// permission gate is on top of that CP auth.
Route::get('telemetry/audit', AuditController::class)
    ->middleware('can:view content audit log')
    ->name('telemetry.audit');

Route::get('telemetry/doctor', DoctorController::class)
    ->middleware('can:view telemetry diagnostics')
    ->name('telemetry.doctor');
