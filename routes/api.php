<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ProxyController;
use App\Http\Controllers\SavedRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::get('google', [AuthController::class, 'googleRedirect']);
    Route::get('google/callback', [AuthController::class, 'googleCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('throttle:proxy')->post('proxy/send', [ProxyController::class, 'send']);

    Route::get('collections/export', [CollectionController::class, 'export']);
    Route::get('collections/{collection}/export', [CollectionController::class, 'exportSingle']);
    Route::post('collections/import', [CollectionController::class, 'import']);
    Route::apiResource('collections', CollectionController::class)->except(['show']);

    Route::apiResource('workspaces', \App\Http\Controllers\WorkspaceController::class);
    Route::get('workspaces/{workspace}/members', [\App\Http\Controllers\WorkspaceController::class, 'members']);
    Route::post('workspaces/{workspace}/invite', [\App\Http\Controllers\WorkspaceController::class, 'invite']);
    Route::delete('workspaces/{workspace}/users/{user}', [\App\Http\Controllers\WorkspaceController::class, 'removeUser']);
    Route::post('workspaces/join/{token}', [\App\Http\Controllers\WorkspaceController::class, 'join']);

    Route::post('requests/reorder', [SavedRequestController::class, 'reorder']);
    Route::apiResource('requests', SavedRequestController::class)
        ->parameters(['requests' => 'savedRequest'])
        ->except(['show']);
});
