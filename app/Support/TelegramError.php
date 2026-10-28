<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class TelegramError
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function make(int $code, string $description, array $parameters = []): JsonResponse
    {
        $body = [
            'ok' => false,
            'error_code' => $code,
            'description' => 'Relay: '.$description,
        ];

        if (! empty($parameters)) {
            $body['parameters'] = $parameters;
        }

        return response()->json($body, $code);
    }
}
