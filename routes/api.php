<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\SavedRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('throttle:proxy')->post('proxy/send', [ProxyController::class, 'send']);

    Route::get('collections/export', [CollectionController::class, 'export']);
    Route::post('collections/import', [CollectionController::class, 'import']);
    Route::apiResource('collections', CollectionController::class)->except(['show']);

    Route::post('requests/reorder', [SavedRequestController::class, 'reorder']);
    Route::apiResource('requests', SavedRequestController::class)
        ->parameters(['requests' => 'savedRequest'])
        ->except(['show']);
});
