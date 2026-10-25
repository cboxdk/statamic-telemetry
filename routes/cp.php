<?php

declare(strict_types=1);

use Cbox\StatamicTelemetry\Http\Controllers\Cp\DoctorController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\ExploreController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\StatamicPageController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\TelemetryController;
use Cbox\StatamicTelemetry\Http\Controllers\Cp\TraceController;
use Cbox\StatamicTelemetry\Telemetry\StatamicPages;
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
Route::get('telemetry/audit', fn (Request $request) => redirect()->to(cp_route('telemetry.page', ['page' => 'content', ...$request->query()])))
    ->middleware('can:view content audit log')
    ->name('telemetry.audit');

Route::get('telemetry/doctor', DoctorController::class)
    ->middleware('can:view telemetry diagnostics')
    ->name('telemetry.doctor');

// The purpose-built Statamic pages: static-cache, stache, glide, forms, content.
Route::get('telemetry/{page}', StatamicPageController::class)
    ->middleware('can:view telemetry')
    ->where('page', implode('|', array_keys(StatamicPages::PAGES)))
    ->name('telemetry.page');
