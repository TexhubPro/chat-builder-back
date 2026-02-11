<?php

use Illuminate\Support\Facades\Route;
use TexHub\Meta\Http\Controllers\InstagramController;

$webhookPath = '/'.ltrim((string) config('meta.instagram.webhook_path', '/instagram-main-webhook'), '/');
$redirectPath = '/'.ltrim((string) config('meta.instagram.redirect_path', '/callback'), '/');

Route::get('/instagram-verify', [InstagramController::class, 'verifyPage'])
    ->middleware('web')
    ->name('instagram.verify');

Route::match(['get', 'post'], $webhookPath, [InstagramController::class, 'webhook'])
    ->name('instagram.webhook');

Route::get($redirectPath, [InstagramController::class, 'callback'])
    ->middleware(['web', 'auth'])
    ->name('instagram.callback');
