<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('v1/livekit/webhook', WebhookController::class)->middleware('throttle:1200,1');
