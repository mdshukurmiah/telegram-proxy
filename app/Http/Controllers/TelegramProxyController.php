<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TelegramForwarder;
use App\Services\WebhookRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TelegramProxyController
{
    public function __invoke(
        Request $request,
        string $token,
        string $method,
        TelegramForwarder $fwd
    ): Response {
        if (config('relay.webhook_rewrite')) {
            $hooks = app(WebhookRegistry::class);

            return match (strtolower($method)) {
                'setwebhook' => $hooks->set($request, $token),
                'deletewebhook' => $hooks->delete($request, $token),
                'getwebhookinfo' => $hooks->info($request, $token),
                default => $fwd->forward($request, $token, $method),
            };
        }

        return $fwd->forward($request, $token, $method);
    }
}
