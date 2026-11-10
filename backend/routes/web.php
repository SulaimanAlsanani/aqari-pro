<?php

use App\Http\Controllers\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [SessionController::class, 'login'])
    ->middleware('throttle:5,1');

Route::post('/logout', [SessionController::class, 'logout'])
    ->middleware('auth:web');
