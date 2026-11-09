<?php

use App\Http\Controllers\Api\PropertyController;
use Illuminate\Support\Facades\Route;

// Requires Laravel Sanctum to be installed and configured in the Laravel app.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/v1/properties', [PropertyController::class, 'index']);
    Route::post('/v1/properties', [PropertyController::class, 'store']);
    Route::get('/v1/properties/{property}', [PropertyController::class, 'show']);
    Route::patch('/v1/properties/{property}', [PropertyController::class, 'update']);
});
