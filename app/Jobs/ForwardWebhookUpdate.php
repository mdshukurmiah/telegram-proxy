<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookTarget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ForwardWebhookUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4; // 1 attempt + 3 retries

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function __construct(public int $targetId, public string $payload) {}

    public function handle(): void
    {
        $row = WebhookTarget::find($this->targetId);
        if (! $row || ! $row->active) {
            return; // webhook removed meanwhile: drop silently
        }

        $headers = [];
        if (! empty($row->target_secret)) {
            $headers['X-Telegram-Bot-Api-Secret-Token'] = (string) $row->target_secret;
        }

        $res = Http::timeout((int) config('relay.webhook_forward_timeout', 15))
            ->withHeaders($headers)
            ->withBody($this->payload, 'application/json')
            ->post($row->target_url);

        if ($res->serverError() || $res->status() === 429) {
            $res->throw(); // retry
        }
        // 4xx other than 429 = n8n rejected it; do not retry
    }

    public function failed(Throwable $e): void
    {
        Log::error('relay.webhook_forward_failed', [
            'target_id' => $this->targetId,
            'error' => class_basename($e),
        ]);
    }
}
