<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

final class HealthController
{
    public function shallow(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => (string) config('app.version', '1.0.0'),
        ]);
    }

    public function deep(): JsonResponse
    {
        $start = hrtime(true);

        try {
            Http::timeout(5)->get(config('relay.telegram_base'));
            $latencyMs = (int) round((hrtime(true) - $start) / 1e6);

            return response()->json([
                'status' => 'ok',
                'telegram' => 'reachable',
                'latency_ms' => $latencyMs,
            ], 200);
        } catch (ConnectionException) {
            return response()->json([
                'status' => 'degraded',
                'telegram' => 'unreachable',
            ], 503);
        }
    }
}
