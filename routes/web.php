<?php

use App\Http\Controllers\Api\AdminOverviewController;
use App\Http\Controllers\Api\ArtworkController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\EntityArtworkController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ProviderCredentialController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('auth')->post('/auth/logout', [AuthController::class, 'logout']);
Route::prefix('api/v1')->middleware('auth')->group(function (): void {
    Route::get('/me', MeController::class);
    Route::get('/discovery', [DiscoveryController::class, 'index']);
    Route::post('/discovery/picks/{pick}/pass', [DiscoveryController::class, 'pass'])->whereUuid('pick')->middleware('throttle:30,1');
    Route::prefix('admin')->middleware('can:owner')->group(function (): void {
        Route::get('/overview', AdminOverviewController::class);
        Route::get('/providers', [ProviderCredentialController::class, 'index']);
        Route::put('/providers/{provider}', [ProviderCredentialController::class, 'update'])->where('provider', 'plex|listenbrainz')->middleware('throttle:5,1');
        Route::delete('/providers/{provider}', [ProviderCredentialController::class, 'destroy'])->where('provider', 'plex|listenbrainz')->middleware('throttle:5,1');
    });
    Route::get('/artwork/{artwork}/{checksum}', ArtworkController::class)->whereUuid('artwork')->where('checksum', '[a-f0-9]{64}')->name('api.artwork');
    Route::get('/entity-artwork/{artwork}/{checksum}', EntityArtworkController::class)->whereUuid('artwork')->where('checksum', '[a-f0-9]{64}')->name('api.entity-artwork');
});
Route::view('/', 'app');
Route::view('/login', 'app');
Route::view('/admin', 'app');
