<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'login'])
    ->middleware('throttle:5,1');

Route::post('/logout', [SessionController::class, 'logout'])
    ->middleware('auth:web');

Route::post('/forgot-password', [PasswordRecoveryController::class, 'requestLink'])
    ->middleware('throttle:3,1');

Route::post('/reset-password', [PasswordRecoveryController::class, 'reset'])
    ->middleware('throttle:5,1');
