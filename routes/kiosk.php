<?php

use App\Http\Controllers\Kiosk\RequestController;
use App\Http\Controllers\Kiosk\SetupController;
use App\Http\Middleware\ResolveKioskTerminal;
use Illuminate\Support\Facades\Route;

/*
| The public kiosk. Loaded in the `kiosk` middleware group (see
| bootstrap/app.php), under its own host or under /kiosk. There is no staff
| sign-in here: a device is set up with a one-time code from ESD.
*/

// Set-up: open to an unpaired device, slowed hard (the code is short).
Route::get('/setup', [SetupController::class, 'show'])->name('kiosk.setup');
Route::post('/setup', [SetupController::class, 'pair'])->middleware('throttle:kiosk-pair')->name('kiosk.pair');

// Everything else needs a paired, active terminal.
Route::middleware(ResolveKioskTerminal::class)->group(function () {
    Route::get('/', [RequestController::class, 'menu'])->name('kiosk.menu');
    Route::get('/request/{slug}', [RequestController::class, 'show'])->name('kiosk.request');
    Route::post('/requests', [RequestController::class, 'store'])->middleware('throttle:kiosk-submit')->name('kiosk.store');
    Route::get('/done', [RequestController::class, 'done'])->name('kiosk.done');
});
