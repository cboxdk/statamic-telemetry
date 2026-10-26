<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Http\Controllers\Cp\DoctorController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\EntryController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\ExploreController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\PageController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\TelemetryController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\TraceController;
use Cbox\StatamicTelemetry\Telemetry\TelemetryPages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Runs inside Statamic's authenticated CP route group (name prefix
// `statamic.cp.`, so these resolve as `cp_route('telemetry.dashboard')` etc.);
// the permission gates are on top of that CP auth.

// The native Telemetry dashboard (Overview / Activity sections).
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

// The content audit lives on the Content page now; old links still land.
// (The route param is set last: an old paginated link carries its own `page=N`.)
Route::get('telemetry/audit', fn (Request $request) => redirect()->to(cp_route('telemetry.page', [...$request->query(), 'page' => 'content'])))
    ->middleware('can:view content audit log')
    ->name('telemetry.audit');

Route::get('telemetry/doctor', DoctorController::class)
    ->middleware('can:view telemetry diagnostics')
    ->name('telemetry.doctor');

// One entry's telemetry story as JSON, for the Telemetry tab in the entry editor.
Route::get('telemetry/entry/{id}', EntryController::class)
    ->middleware('can:view telemetry')
    ->name('telemetry.entry');

// The purpose-built pages: requests, jobs, …, static-cache, stache, glide, forms, content.
Route::get('telemetry/{page}', PageController::class)
    ->middleware('can:view telemetry')
    ->where('page', implode('|', array_keys(TelemetryPages::PAGES)))
    ->name('telemetry.page');
