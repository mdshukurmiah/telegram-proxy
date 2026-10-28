<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ForwardWebhookUpdate;
use App\Models\WebhookTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookReceiveController
{
    public function __invoke(Request $request, string $botId, string $routeSecret): JsonResponse
    {
        $row = WebhookTarget::where('bot_id', $botId)
            ->where('route_secret', $routeSecret)
            ->where('active', true)
            ->first();

        if (! $row) {
            return response()->json(['ok' => false], 404);
        }

        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (! hash_equals((string) $row->telegram_secret, $given)) {
            return response()->json(['ok' => false], 403);
        }

        ForwardWebhookUpdate::dispatch((int) $row->id, (string) $request->getContent());

        return response()->json(['ok' => true]);
    }
}
