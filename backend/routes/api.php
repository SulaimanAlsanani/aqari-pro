<?php

use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

// Requires Laravel Sanctum to be installed and configured in the Laravel app.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/v1/properties', [PropertyController::class, 'index']);
    Route::post('/v1/properties', [PropertyController::class, 'store']);
    Route::get('/v1/properties/{property}', [PropertyController::class, 'show']);
    Route::patch('/v1/properties/{property}', [PropertyController::class, 'update']);

    Route::get('/v1/properties/{property}/units', [UnitController::class, 'index']);
    Route::post('/v1/properties/{property}/units', [UnitController::class, 'store']);
    Route::get('/v1/properties/{property}/units/{unit}', [UnitController::class, 'show']);
    Route::patch('/v1/properties/{property}/units/{unit}', [UnitController::class, 'update']);

    Route::get('/v1/tenants', [TenantController::class, 'index']);
    Route::post('/v1/tenants', [TenantController::class, 'store']);
    Route::get('/v1/tenants/{tenant}', [TenantController::class, 'show']);
    Route::patch('/v1/tenants/{tenant}', [TenantController::class, 'update']);

    Route::get('/v1/contracts', [ContractController::class, 'index']);
    Route::post('/v1/contracts', [ContractController::class, 'store']);
    Route::get('/v1/contracts/{contract}', [ContractController::class, 'show']);

    Route::get('/v1/payments', [PaymentController::class, 'index']);
    Route::get('/v1/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/v1/dues/{due}/payments', [PaymentController::class, 'store']);
});
