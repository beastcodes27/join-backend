<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\NotificationController;
use App\Http\Middleware\ActiveAccount;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Join Meeting API', 'company' => 'DevTZ']));
Route::prefix('api/v1')->middleware('throttle:api')->group(function () {
    Route::post('auth/firebase', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::middleware(['auth:sanctum', ActiveAccount::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('api/v1/meetings')->middleware([ActiveAccount::class, 'throttle:api'])->group(function () {
    Route::get('/', [MeetingController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/', [MeetingController::class, 'store'])->middleware(['auth:sanctum', 'throttle:create']);
    Route::get('{meeting}', [MeetingController::class, 'show']);
    Route::post('{meeting}/admission-request', [MeetingController::class, 'admission'])->middleware('throttle:join');
    Route::post('{meeting}/join', [MeetingController::class, 'join'])->middleware('throttle:join');
    Route::post('{meeting}/leave', [MeetingController::class, 'leave']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('{meeting}/participants', [MeetingController::class, 'participants']);
        foreach (['admit', 'reject', 'remove', 'end', 'access'] as $action) {
            Route::post('{meeting}/'.$action, [MeetingController::class, $action]);
        }
    });
});

Route::prefix('api/v1')->middleware(['auth:sanctum', ActiveAccount::class, 'throttle:api'])->group(function () {
    Route::get('bookings', [BookingController::class, 'index']);
    Route::post('meetings/{meeting}/book', [BookingController::class, 'store']);
    Route::post('meetings/{meeting}/unbook', [BookingController::class, 'cancel']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
});
