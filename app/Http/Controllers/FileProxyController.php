<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\TelegramError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

final class FileProxyController
{
    public function __invoke(string $token, string $path): Response
    {
        if (str_contains($path, '..')) {
            return TelegramError::make(400, 'Invalid path');
        }

        try {
            $res = Http::connectTimeout((int) config('relay.connect_timeout', 10))
                ->timeout((int) config('relay.file_timeout', 120))
                ->withOptions(['stream' => true])
                ->get(config('relay.telegram_base')."/file/bot{$token}/{$path}");
        } catch (ConnectionException) {
            return TelegramError::make(502, 'Upstream unreachable');
        }

        $body = $res->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(8192);
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, $res->status(), array_filter([
            'Content-Type' => $res->header('Content-Type') ?: null,
            'Content-Length' => $res->header('Content-Length') ?: null,
        ]));
    }
}
