<?php

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AssistantChannelController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChatMessageController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\CompanyClientController;
use App\Http\Controllers\Api\CompanyClientOrderController;
use App\Http\Controllers\Api\CompanySubscriptionController;
use App\Http\Controllers\Api\InstagramIntegrationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\SubscriptionPlanController;
use App\Http\Controllers\Api\TelegramIntegrationController;
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

Route::prefix('company')
    ->middleware([EnsureUserIsActive::class])
    ->group(function (): void {
        Route::get('/settings', [CompanyController::class, 'show']);
        Route::put('/settings', [CompanyController::class, 'update']);
    });

Route::prefix('client-requests')
    ->middleware([EnsureUserIsActive::class])
    ->group(function (): void {
        Route::get('/', [CompanyClientOrderController::class, 'index']);
        Route::patch('/{orderId}', [CompanyClientOrderController::class, 'update'])->whereNumber('orderId');
        Route::delete('/{orderId}', [CompanyClientOrderController::class, 'destroy'])->whereNumber('orderId');
    });

Route::prefix('client-base')
    ->middleware([EnsureUserIsActive::class])
    ->group(function (): void {
        Route::get('/', [CompanyClientController::class, 'index']);
        Route::get('/{clientId}', [CompanyClientController::class, 'show'])->whereNumber('clientId');
    });

Route::post('/billing/alif/callback', [InvoiceController::class, 'alifCallback'])
    ->name('api.billing.alif.callback');

Route::get('/integrations/instagram/callback', [InstagramIntegrationController::class, 'callback'])
    ->name('api.integrations.instagram.callback');

Route::post('/integrations/telegram/webhook/{assistantChannelId}', [TelegramIntegrationController::class, 'webhook'])
    ->whereNumber('assistantChannelId')
    ->name('api.integrations.telegram.webhook');

Route::post('/chats/webhook/{channel}', [ChatController::class, 'webhook'])
    ->name('api.chats.webhook');

Route::prefix('assistants')
    ->middleware([EnsureUserIsActive::class])
    ->group(function (): void {
        Route::get('/', [AssistantController::class, 'index']);
        Route::post('/', [AssistantController::class, 'store']);
        Route::put('/{assistantId}', [AssistantController::class, 'update'])->whereNumber('assistantId');
        Route::post('/{assistantId}/start', [AssistantController::class, 'start'])->whereNumber('assistantId');
        Route::post('/{assistantId}/stop', [AssistantController::class, 'stop'])->whereNumber('assistantId');
        Route::delete('/{assistantId}', [AssistantController::class, 'destroy'])->whereNumber('assistantId');
        Route::post('/{assistantId}/instruction-files', [AssistantController::class, 'uploadInstructionFiles'])
            ->whereNumber('assistantId');
        Route::delete('/{assistantId}/instruction-files/{fileId}', [AssistantController::class, 'destroyInstructionFile'])
            ->whereNumber('assistantId')
            ->whereNumber('fileId');
    });

Route::prefix('assistant-channels')
    ->middleware([EnsureUserIsActive::class])
    ->group(function (): void {
        Route::get('/', [AssistantChannelController::class, 'index']);
        Route::post('/{assistantId}/instagram/connect', [InstagramIntegrationController::class, 'redirect'])
            ->whereNumber('assistantId');
        Route::post('/{assistantId}/telegram/connect', [TelegramIntegrationController::class, 'connect'])
            ->whereNumber('assistantId');
        Route::patch('/{assistantId}/{channel}', [AssistantChannelController::class, 'update'])
            ->whereNumber('assistantId');
        Route::delete('/{assistantId}/{channel}', [AssistantChannelController::class, 'destroy'])
            ->whereNumber('assistantId');
    });

Route::prefix('chats')
    ->middleware([EnsureUserIsActive::class])
    ->group(function (): void {
        Route::get('/', [ChatController::class, 'index']);
        Route::get('/{chatId}', [ChatController::class, 'show'])->whereNumber('chatId');
        Route::get('/{chatId}/insights', [ChatController::class, 'insights'])->whereNumber('chatId');
        Route::patch('/{chatId}/ai-enabled', [ChatController::class, 'updateAiEnabled'])->whereNumber('chatId');
        Route::post('/{chatId}/reset', [ChatController::class, 'resetAssistantChat'])->whereNumber('chatId');
        Route::post('/{chatId}/read', [ChatController::class, 'markAsRead'])->whereNumber('chatId');
        Route::post('/{chatId}/messages', [ChatMessageController::class, 'store'])->whereNumber('chatId');
        Route::post('/{chatId}/tasks', [ChatController::class, 'createTask'])->whereNumber('chatId');
        Route::post('/{chatId}/orders', [ChatController::class, 'createOrder'])->whereNumber('chatId');
        Route::post('/{chatId}/assistant-reply', [ChatMessageController::class, 'assistantReply'])
            ->whereNumber('chatId');
    });
