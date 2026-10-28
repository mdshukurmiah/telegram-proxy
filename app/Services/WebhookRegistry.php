<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WebhookTarget;
use App\Support\TelegramError;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class WebhookRollbackException extends Exception
{
    public function __construct(public Response $response)
    {
        parent::__construct('Telegram setWebhook returned ok:false');
    }
}

final class WebhookRegistry
{
    public function __construct(private TelegramForwarder $fwd) {}

    public function set(Request $request, string $token): Response
    {
        $botId = (string) strstr($token, ':', true);
        $params = array_merge($request->query(), $request->request->all(), (array) $request->json()->all());
        unset($params['relay_key']);
        $target = (string) ($params['url'] ?? '');

        // Empty url = Telegram's way of removing the webhook
        if ($target === '') {
            return $this->delete($request, $token);
        }

        if (! $this->isAllowedTarget($target)) {
            return TelegramError::make(400, 'Webhook url must be public https');
        }

        try {
            return DB::transaction(function () use ($botId, $token, $target, $params, $request): Response {
                $row = WebhookTarget::firstOrNew(['bot_id' => $botId, 'target_url' => $target]);
                $row->fill([
                    'target_secret' => $params['secret_token'] ?? null,
                    'route_secret' => $row->route_secret ?: Str::random(32),
                    'telegram_secret' => $row->telegram_secret ?: Str::random(64),
                    'active' => true,
                ])->save();

                $params['url'] = $this->hookUrl($row);
                $params['secret_token'] = $row->telegram_secret;

                $res = $this->fwd->sendParams($token, 'setWebhook', $params, $request->allFiles());
                $data = json_decode((string) $res->getContent(), true);

                if (! ($data['ok'] ?? false)) {
                    throw new WebhookRollbackException($res);
                }

                WebhookTarget::where('bot_id', $botId)->whereKeyNot($row->id)->update(['active' => false]);

                return $res;
            });
        } catch (WebhookRollbackException $e) {
            return $e->response;
        }
    }

    public function delete(Request $request, string $token): Response
    {
        $res = $this->fwd->forward($request, $token, 'deleteWebhook');
        $data = json_decode((string) $res->getContent(), true);

        if ($data['ok'] ?? false) {
            WebhookTarget::where('bot_id', strstr($token, ':', true))->update(['active' => false]);
        }

        return $res;
    }

    public function info(Request $request, string $token): Response
    {
        $res = $this->fwd->forward($request, $token, 'getWebhookInfo');
        $data = json_decode((string) $res->getContent(), true);
        $row = WebhookTarget::where('bot_id', strstr($token, ':', true))->where('active', true)->first();

        if ($row && ($data['ok'] ?? false) && ($data['result']['url'] ?? null) === $this->hookUrl($row)) {
            $data['result']['url'] = $row->target_url;   // n8n sees its own URL

            return response()->json($data, $res->getStatusCode());
        }

        return $res;
    }

    public function hookUrl(WebhookTarget $row): string
    {
        $prefix = (string) config('relay.path_prefix');

        return rtrim((string) config('app.url'), '/').'/'.$prefix."/hook/{$row->bot_id}/{$row->route_secret}";
    }

    private function isAllowedTarget(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (config('relay.allow_private_targets')) {
            return true;
        }

        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        // If host is an IP literal, reject private or reserved ranges
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        // Check resolved IP if DNS resolves it
        $resolved = gethostbyname($host);
        if (filter_var($resolved, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
