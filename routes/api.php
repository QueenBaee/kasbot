<?php

declare(strict_types=1);

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware(VerifyTelegramWebhookSecret::class);
