<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TelegramError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class IpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = (array) config('relay.allowed_ips', []);
        if (empty($allowedIps)) {
            return $next($request);
        }

        $clientIp = $request->ip();
        if ($clientIp === null || ! IpUtils::checkIp($clientIp, $allowedIps)) {
            return TelegramError::make(403, 'IP not allowed');
        }

        return $next($request);
    }
}
