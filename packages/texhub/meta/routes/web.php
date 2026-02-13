<?php

use App\Http\Controllers\Api\InstagramIntegrationController;
use Illuminate\Support\Facades\Route;
use TexHub\Meta\Http\Controllers\InstagramController;

$webhookPath = '/'.ltrim((string) config('meta.instagram.webhook_path', '/instagram-main-webhook'), '/');
$redirectPath = '/'.ltrim((string) config('meta.instagram.redirect_path', '/callback'), '/');

Route::get('/instagram-verify', [InstagramController::class, 'verifyPage'])
    ->middleware('web')
    ->name('instagram.verify');

Route::match(['get', 'post'], $webhookPath, [InstagramController::class, 'webhook'])
    ->name('instagram.webhook');

if ($redirectPath !== '/callback') {
    Route::get($redirectPath, [InstagramController::class, 'callback'])
        ->middleware(['web'])
        ->name('instagram.callback');
}

Route::get('/callback', [InstagramIntegrationController::class, 'callback'])
    ->middleware(['web'])
    ->name('instagram.callback');
