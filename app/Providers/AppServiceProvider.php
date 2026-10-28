<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\TelegramError;
use App\Support\TokenMasker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Monolog\LogRecord;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('relay-bot', function (Request $r) {
            $botId = (string) ($r->attributes->get('bot_id') ?? ($r->ip() ?? 'unknown'));

            return Limit::perMinute((int) config('relay.rate_limit', 120))
                ->by('bot:'.$botId)
                ->response(fn (Request $req, array $headers) => TelegramError::make(429, 'Relay rate limit', ['retry_after' => (int) ($headers['Retry-After'] ?? 60)])
                );
        });

        RateLimiter::for('relay-hook', fn (Request $r) => Limit::perMinute(600)->by('hook:'.((string) $r->route('botId')))
        );

        if ($this->app->isProduction()) {
            throw_if(
                strlen((string) config('relay.path_prefix')) < 16,
                new RuntimeException('RELAY_PATH_PREFIX too short')
            );
            throw_if(
                empty(config('relay.allowed_bot_ids')),
                new RuntimeException('RELAY_ALLOWED_BOT_IDS empty')
            );
        }

        // Mask tokens in every log record
        if (method_exists(Log::getLogger(), 'pushProcessor')) {
            Log::getLogger()->pushProcessor(fn (LogRecord $record) => $record->with(message: TokenMasker::mask($record->message))
            );
        }
    }
}
