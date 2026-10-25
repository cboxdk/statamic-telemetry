<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Http\Controllers\Cp\AuditController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\DoctorController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\ExploreController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\TelemetryController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\TraceController;
use Illuminate\Support\Facades\Route;

// The native Telemetry dashboard (Overview / Activity / Statamic sections).
Route::get('telemetry', TelemetryController::class)
    ->middleware('can:view telemetry')
    ->name('telemetry.dashboard');

// Explore — requests / traces / logs / errors with facets and group-by.
Route::get('telemetry/explore', ExploreController::class)
    ->middleware('can:view telemetry')
    ->name('telemetry.explore');

// One trace as JSON, for the trace drawer (opens from any telemetry page).
Route::get('telemetry/trace/{id}', TraceController::class)
    ->middleware('can:view telemetry')
    ->where('id', '[A-Za-z0-9]+')
    ->name('telemetry.trace');

// Runs inside Statamic's authenticated CP route group (name prefix
// `statamic.cp.`, so these resolve as `cp_route('telemetry.audit')` etc.); the
// permission gate is on top of that CP auth.
Route::get('telemetry/audit', AuditController::class)
    ->middleware('can:view content audit log')
    ->name('telemetry.audit');

Route::get('telemetry/doctor', DoctorController::class)
    ->middleware('can:view telemetry diagnostics')
    ->name('telemetry.doctor');
