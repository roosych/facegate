<?php

use App\Http\Controllers\HikvisionEventWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/hikvision/{terminal}/events/{token}', [HikvisionEventWebhookController::class, 'store']);
