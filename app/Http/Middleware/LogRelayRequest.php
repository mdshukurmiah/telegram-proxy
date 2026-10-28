<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\RelayLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class LogRelayRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('relay_start_time', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('relay.db_logging', true)) {
            return;
        }

        try {
            $start = $request->attributes->get('relay_start_time');
            $durationMs = $start !== null ? (int) round((hrtime(true) - (int) $start) / 1e6) : 0;

            $token = (string) $request->route('token');
            $botId = (string) ($request->attributes->get('bot_id') ?? (strstr($token, ':', true) ?: 'unknown'));
            $method = (string) ($request->route('method') ?? ($request->route('path') ? 'file' : 'unknown'));
            $error = $request->attributes->get('relay_error');

            RelayLog::create([
                'bot_id' => $botId,
                'method' => $method,
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'error' => $error ? (string) $error : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Logging must never break a relay call
        }
    }
}
