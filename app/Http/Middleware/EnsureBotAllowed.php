<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TelegramError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBotAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $botId = strstr((string) $request->route('token'), ':', true);
        if ($botId === false || ! in_array($botId, config('relay.allowed_bot_ids'), true)) {
            return TelegramError::make(403, 'Bot not allowed on this relay');
        }

        $request->attributes->set('bot_id', $botId);

        return $next($request);
    }
}
