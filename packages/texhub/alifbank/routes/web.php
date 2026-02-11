<?php

use Illuminate\Support\Facades\Route;
use TexHub\AlifBank\Http\Controllers\AlifBankController;

Route::middleware('web')->group(function (): void {
    Route::post('/alifbank/callback', [AlifBankController::class, 'callback'])
        ->name('alifbank.callback');
});
