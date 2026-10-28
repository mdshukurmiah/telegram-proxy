<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TelegramError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRelayKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = config('relay.relay_key');
        if ($expectedKey === null || $expectedKey === '') {
            return $next($request);
        }

        $providedKey = $request->header('X-Relay-Key') ?? $request->query('relay_key');

        if ($providedKey === null || ! is_string($providedKey) || ! hash_equals((string) $expectedKey, $providedKey)) {
            return TelegramError::make(401, 'Invalid relay key');
        }

        return $next($request);
    }
}
