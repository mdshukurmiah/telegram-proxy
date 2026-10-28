<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\TelegramError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class TelegramForwarder
{
    /** Forward the incoming request as-is. */
    public function forward(Request $request, string $token, string $method): Response
    {
        $url = $this->url($token, $method, $request->query());
        $type = (string) $request->header('Content-Type', '');

        return $this->guard(function () use ($request, $url, $type) {
            $http = $this->client($request);

            if (str_starts_with($type, 'multipart/form-data') || ! empty($request->allFiles())) {
                foreach ($request->allFiles() as $field => $file) {
                    if (is_array($file)) {
                        abort(TelegramError::make(400, "File arrays not supported: {$field}"));
                    }
                    $http = $http->attach(
                        $field,
                        fopen($file->getRealPath(), 'r'),
                        $file->getClientOriginalName()
                    );
                }

                return $http->post($url, $this->plainFields($request->request->all()));
            }

            $raw = $request->getContent();
            if ($raw === '') {
                return $request->isMethod('GET') ? $http->get($url) : $http->post($url);
            }

            return $http->withBody($raw, $type ?: 'application/json')->post($url);
        });
    }

    /** Send rebuilt params (used by webhook rewrite). Files kept as multipart. */
    public function sendParams(string $token, string $method, array $params, array $files = []): Response
    {
        $url = $this->url($token, $method, []);

        return $this->guard(function () use ($url, $params, $files) {
            $http = $this->client();
            if (empty($files)) {
                return $http->asJson()->post($url, $params);
            }

            foreach ($files as $field => $file) {
                $http = $http->attach(
                    $field,
                    fopen($file->getRealPath(), 'r'),
                    $file->getClientOriginalName()
                );
            }

            return $http->post($url, $this->plainFields($params));
        });
    }

    private function client(?Request $request = null): PendingRequest
    {
        $accept = ($request !== null && $request->hasHeader('Accept'))
            ? (string) $request->header('Accept')
            : 'application/json';

        return Http::connectTimeout((int) config('relay.connect_timeout', 10))
            ->timeout((int) config('relay.timeout', 60))
            ->accept($accept)
            ->withUserAgent('tg-relay/1.0')
            // Retry ONLY when no connection was made (DNS / refused). Never after a request was sent.
            ->retry(
                (int) config('relay.connect_retries', 2) + 1,
                500,
                fn ($e) => $e instanceof ConnectionException && preg_match('/cURL error (6|7):/', $e->getMessage()),
                throw: false
            );
    }

    private function url(string $token, string $method, array $query): string
    {
        unset($query['relay_key']);
        $url = config('relay.telegram_base')."/bot{$token}/{$method}";

        return ! empty($query) ? $url.'?'.http_build_query($query) : $url;
    }

    private function plainFields(array $fields): array
    {
        unset($fields['relay_key']);

        return array_map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v), $fields);
    }

    /** Wrap upstream call; map transport failures to Telegram-shaped errors. */
    private function guard(callable $call): Response
    {
        try {
            $res = $call();
        } catch (ConnectionException $e) {
            $timeout = str_contains($e->getMessage(), 'cURL error 28');
            Log::warning('relay.upstream_failed', ['error' => $timeout ? 'timeout' : 'connect']);
            request()->attributes->set('relay_error', $timeout ? 'timeout' : 'connect');

            return TelegramError::make(
                $timeout ? 504 : 502,
                $timeout ? 'Upstream timeout' : 'Upstream unreachable'
            );
        }

        return response($res->body(), $res->status())
            ->header('Content-Type', $res->header('Content-Type') ?: 'application/json');
    }
}
