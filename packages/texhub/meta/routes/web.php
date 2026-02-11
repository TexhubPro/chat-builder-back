<?php

use Illuminate\Support\Facades\Route;
use TexHub\Meta\Http\Controllers\InstagramController;

Route::middleware('web')->group(function (): void {
    Route::get('/instagram-verify', [InstagramController::class, 'verifyPage'])->name('instagram.verify');
    Route::match(['get', 'post'], '/instagram-main-webhook', [InstagramController::class, 'webhook'])
        ->name('instagram.webhook');
    Route::get('/callback', [InstagramController::class, 'callback'])
        ->middleware('auth')
        ->name('instagram.callback');
});
