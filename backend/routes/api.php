<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\DocumentController;

Route::post('/login',[AuthController::class,'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
 Route::get('/me',[AuthController::class,'me']);
 Route::post('/logout',[AuthController::class,'logout']);
 Route::get('/portfolio',[PortfolioController::class,'index']);
 Route::post('/properties',[PortfolioController::class,'storeProperty']);
 Route::get('/properties/{property}',[PortfolioController::class,'showProperty']);
 Route::post('/properties/{property}/units',[PortfolioController::class,'storeUnit']);
 Route::post('/tenants',[PortfolioController::class,'storeTenant']);
 Route::post('/leases',[PortfolioController::class,'storeLease']);
 Route::post('/leases/{lease}/payments',[PortfolioController::class,'storePayment']);
 Route::post('/documents',[DocumentController::class,'store']);
 Route::get('/documents/{document}',[DocumentController::class,'download']);
 Route::delete('/documents/{document}',[DocumentController::class,'destroy']);
});
