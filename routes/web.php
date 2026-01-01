<?php

use App\Http\Controllers\Auth\StravaOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Strava OAuth routes
Route::get('/auth/strava', [StravaOAuthController::class, 'redirect'])->name('strava.redirect');
Route::get('/auth/strava/callback', [StravaOAuthController::class, 'callback'])->name('strava.callback');
