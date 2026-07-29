<?php

use App\Http\Controllers\AdmsController;
use App\Http\Controllers\HikvisionEventWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/adms/cdata', [AdmsController::class, 'handle']);
Route::post('/hikvision/{terminal}/events/{token}', [HikvisionEventWebhookController::class, 'store']);
