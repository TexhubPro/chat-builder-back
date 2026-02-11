<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanySubscriptionController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Middleware\EnsureApiTokenIsValid;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/verify-email-code', [AuthController::class, 'verifyEmailCode']);
    Route::post('/resend-verification-code', [AuthController::class, 'resendVerificationCode']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/oauth/{provider}/redirect', [AuthController::class, 'socialRedirect']);
    Route::get('/oauth/{provider}/callback', [AuthController::class, 'socialCallback']);
    Route::middleware([EnsureApiTokenIsValid::class])->group(function (): void {
        Route::get('/moderation-status', [AuthController::class, 'moderationStatus']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::middleware([EnsureUserIsActive::class])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/profile', [AuthController::class, 'updateProfile']);
    });
});

Route::prefix('billing')
    ->middleware([EnsureUserIsActive::class])
    ->group(function (): void {
        Route::get('/plans', [SubscriptionPlanController::class, 'index']);
        Route::get('/subscription', [CompanySubscriptionController::class, 'show']);
        Route::post('/checkout', [CompanySubscriptionController::class, 'checkout']);
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices/{invoice}/pay', [InvoiceController::class, 'pay']);
    });

Route::post('/billing/alif/callback', [InvoiceController::class, 'alifCallback'])
    ->name('api.billing.alif.callback');
