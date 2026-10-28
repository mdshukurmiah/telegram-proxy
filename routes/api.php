<?php

declare(strict_types=1);

use App\Http\Controllers\FileProxyController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelegramProxyController;
use App\Http\Controllers\WebhookReceiveController;
use Illuminate\Support\Facades\Route;

$token = '[0-9]{5,15}:[A-Za-z0-9_-]{30,64}';

Route::get('/health', [HealthController::class, 'shallow']);

Route::prefix(config('relay.path_prefix'))->group(function () use ($token): void {

    Route::middleware(['relay.ip', 'relay.key'])->group(function () use ($token): void {
        Route::get('/health/deep', [HealthController::class, 'deep']);

        Route::match(['GET', 'POST'], '/bot{token}/{method}', TelegramProxyController::class)
            ->where(['token' => $token, 'method' => '[A-Za-z]{2,64}'])
            ->middleware(['relay.bot', 'throttle:relay-bot', 'relay.log']);

        Route::get('/file/bot{token}/{path}', FileProxyController::class)
            ->where(['token' => $token, 'path' => '.+'])
            ->middleware(['relay.bot', 'throttle:relay-bot', 'relay.log']);
    });

    // Called by Telegram: no IP allowlist, no relay key (SR-4, SR-6)
    Route::post('/hook/{botId}/{routeSecret}', WebhookReceiveController::class)
        ->where(['botId' => '[0-9]{5,15}', 'routeSecret' => '[A-Za-z0-9]{32}'])
        ->middleware('throttle:relay-hook');
});

Route::fallback(fn () => response('', 404));
