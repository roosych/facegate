<?php

use App\Http\Controllers\AccessEventController;
use App\Http\Controllers\AlcoholDebugController;
use App\Http\Controllers\AlcoholStatusController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\HikvisionSyncController;
use App\Http\Controllers\HikvisionTerminalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RusGuardController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SyncLogController;
use App\Http\Controllers\TurnstileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/status', [DashboardController::class, 'status'])->name('dashboard.status');

    Route::get('/access-points', [TurnstileController::class, 'index'])->name('access-points.index');
    Route::get('/access-points/create', [TurnstileController::class, 'create'])->name('access-points.create');
    Route::post('/access-points', [TurnstileController::class, 'store'])->name('access-points.store');
    Route::get('/access-points/{turnstile}/edit', [TurnstileController::class, 'edit'])->name('access-points.edit');
    Route::patch('/access-points/{turnstile}', [TurnstileController::class, 'update'])->name('access-points.update');
    Route::get('/access-points/check-points', [TurnstileController::class, 'checkPoints'])->name('access-points.check-points');
    Route::post('/access-points/create-point', [TurnstileController::class, 'createPoint'])->name('access-points.create-point');
    Route::get('/access-points/{turnstile}/check-count', [TurnstileController::class, 'checkCount'])->name('access-points.check-count');
    Route::get('/access-points/{turnstile}/rusguard-employees', [TurnstileController::class, 'rusguardEmployees'])->name('access-points.rusguard-employees');
    Route::get('/access-points/{turnstile}', [TurnstileController::class, 'show'])->name('access-points.show');

    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('/devices/create', [DeviceController::class, 'create'])->name('devices.create');
    Route::post('/devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::post('/devices/sync-from-zkbio', [DeviceController::class, 'syncFromZKBio'])->name('devices.sync-from-zkbio');
    Route::post('/devices/{device}/push-from-access-point', [DeviceController::class, 'pushFromAccessPoint'])->name('devices.push-from-access-point');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/{employee}/photo', [EmployeeController::class, 'photo'])->name('employees.photo');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');

    Route::get('/alcohol', [AlcoholStatusController::class, 'index'])->name('alcohol.index');
    Route::post('/alcohol/grace-period', [AlcoholStatusController::class, 'updateGracePeriod'])->name('alcohol.grace-period');
    Route::get('/alcohol/{employee}/debug', [AlcoholDebugController::class, 'show'])->name('alcohol.debug');
    Route::post('/alcohol/{employee}/debug/snapshot', [AlcoholDebugController::class, 'snapshot'])->name('alcohol.debug.snapshot');
    Route::post('/alcohol/{employee}/debug/reset-skip', [AlcoholDebugController::class, 'resetSkip'])->name('alcohol.debug.reset-skip');
    Route::post('/alcohol/{employee}/debug/randomize-name', [AlcoholDebugController::class, 'randomizeName'])->name('alcohol.debug.randomize-name');

    Route::get('/events', [AccessEventController::class, 'index'])->name('events.index');
    Route::post('/events/fetch/{terminal}', [AccessEventController::class, 'fetchFromTerminal'])->name('events.fetch-terminal');
    Route::get('/events/fetch/{terminal}/status', [AccessEventController::class, 'fetchStatus'])->name('events.fetch-status');

    Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
    Route::get('/sync/status', [SyncController::class, 'syncStatus'])->name('sync.status');
    Route::post('/sync/turnstile/{turnstile}', [SyncController::class, 'syncTurnstile'])->name('sync.turnstile');
    Route::get('/sync/turnstile/{turnstile}/status', [SyncController::class, 'syncTurnstileStatus'])->name('sync.turnstile.status');
    Route::post('/sync/turnstile/{turnstile}/push', [SyncController::class, 'pushTurnstile'])->name('sync.push-turnstile');
    Route::post('/sync/all', [SyncController::class, 'syncAll'])->name('sync.all');

    Route::get('/logs', [SyncLogController::class, 'index'])->name('logs.index');

    Route::get('/rusguard/access-levels', [RusGuardController::class, 'accessLevels'])->name('rusguard.access-levels');

    // Hikvision terminals
    Route::get('/hikvision', [HikvisionTerminalController::class, 'index'])->name('hikvision.index');
    Route::get('/hikvision/create', [HikvisionTerminalController::class, 'create'])->name('hikvision.create');
    Route::post('/hikvision', [HikvisionTerminalController::class, 'store'])->name('hikvision.store');
    Route::get('/hikvision/{hikvision}/edit', [HikvisionTerminalController::class, 'edit'])->name('hikvision.edit');
    Route::patch('/hikvision/{hikvision}', [HikvisionTerminalController::class, 'update'])->name('hikvision.update');
    Route::delete('/hikvision/{hikvision}', [HikvisionTerminalController::class, 'destroy'])->name('hikvision.destroy');
    Route::patch('/hikvision/{hikvision}/link', [HikvisionTerminalController::class, 'linkTurnstile'])->name('hikvision.link-turnstile');
    Route::get('/hikvision/{hikvision}/check-connection', [HikvisionTerminalController::class, 'checkConnection'])->name('hikvision.check-connection');
    Route::get('/hikvision/{hikvision}/employees', [HikvisionTerminalController::class, 'employees'])->name('hikvision.employees');
    Route::delete('/hikvision/{hikvision}/employees', [HikvisionTerminalController::class, 'deleteAllEmployees'])->name('hikvision.employees.delete-all');
    Route::post('/hikvision/{hikvision}/employees/resync', [HikvisionTerminalController::class, 'resyncEmployee'])->name('hikvision.employees.resync');
    Route::get('/hikvision/{hikvision}/alcohol', [HikvisionTerminalController::class, 'alcoholSettings'])->name('hikvision.alcohol');
    Route::put('/hikvision/{hikvision}/alcohol', [HikvisionTerminalController::class, 'updateAlcoholSettings'])->name('hikvision.alcohol.update');
    Route::patch('/hikvision/{hikvision}/alcohol/toggle', [HikvisionTerminalController::class, 'toggleAlcohol'])->name('hikvision.alcohol.toggle');

    // Hikvision sync
    Route::get('/hikvision-sync', [HikvisionSyncController::class, 'index'])->name('hikvision.sync.index');
    Route::post('/hikvision-sync/{hikvision}', [HikvisionSyncController::class, 'syncTerminal'])->name('hikvision.sync.terminal');
    Route::get('/hikvision-sync/{hikvision}/status', [HikvisionSyncController::class, 'syncStatus'])->name('hikvision.sync.status');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
