<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ForwardWebhookUpdate;
use App\Models\WebhookTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class WebhookRewriteTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix;

    private string $token = '111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = (string) config('relay.path_prefix');
        config(['relay.webhook_rewrite' => true]);
    }

    public function test_t24_set_webhook_rewrites_url_and_secret_and_saves_target(): void
    {
        Http::preventStrayRequests();

        $targetUrl = 'https://n8n.example.com/webhook/tg';
        $n8nSecret = 'n8n_original_secret_123';

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/setWebhook" => function (ClientRequest $request) {
                $data = json_decode($request->body(), true);

                $this->assertStringContainsString("/{$this->prefix}/hook/111111/", $data['url']);
                $this->assertNotSame('n8n_original_secret_123', $data['secret_token']);
                $this->assertSame(64, strlen($data['secret_token']));

                return Http::response(['ok' => true, 'result' => true], 200);
            },
        ]);

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/setWebhook", [
            'url' => $targetUrl,
            'secret_token' => $n8nSecret,
        ]);

        $response->assertStatus(200)
            ->assertJson(['ok' => true, 'result' => true]);

        $this->assertDatabaseHas('webhook_targets', [
            'bot_id' => '111111',
            'target_url' => $targetUrl,
            'active' => true,
        ]);

        $row = WebhookTarget::first();
        $this->assertSame($n8nSecret, $row->target_secret);
        $this->assertSame(32, strlen($row->route_secret));
        $this->assertSame(64, strlen($row->telegram_secret));
    }

    public function test_t25_set_webhook_twice_reuses_row_and_preserves_route_secret(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/setWebhook" => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $targetUrl = 'https://n8n.example.com/webhook/tg';

        $this->postJson("/{$this->prefix}/bot{$this->token}/setWebhook", ['url' => $targetUrl])->assertStatus(200);
        $firstRow = WebhookTarget::first();
        $firstSecret = $firstRow->route_secret;

        $this->postJson("/{$this->prefix}/bot{$this->token}/setWebhook", ['url' => $targetUrl])->assertStatus(200);

        $this->assertSame(1, WebhookTarget::where('bot_id', '111111')->count());
        $secondRow = WebhookTarget::first();
        $this->assertSame($firstSecret, $secondRow->route_secret);
    }

    public function test_t26_set_webhook_rollback_when_telegram_returns_ok_false(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/setWebhook" => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: can not resolve host',
            ], 400),
        ]);

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/setWebhook", [
            'url' => 'https://unresolvable.example.com/hook',
        ]);

        $response->assertStatus(400)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, WebhookTarget::where('bot_id', '111111')->where('active', true)->count());
    }

    public function test_t27_set_webhook_rejects_http_and_private_targets(): void
    {
        Http::preventStrayRequests();

        config(['relay.allow_private_targets' => false]);

        // HTTP URL
        $res1 = $this->postJson("/{$this->prefix}/bot{$this->token}/setWebhook", [
            'url' => 'http://example.com/hook',
        ]);
        $res1->assertStatus(400)
            ->assertJson([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Relay: Webhook url must be public https',
            ]);

        // Private IP
        $res2 = $this->postJson("/{$this->prefix}/bot{$this->token}/setWebhook", [
            'url' => 'https://192.168.1.5/hook',
        ]);
        $res2->assertStatus(400)
            ->assertJson([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Relay: Webhook url must be public https',
            ]);
    }

    public function test_t28_get_webhook_info_rewrites_relay_url_to_target_url(): void
    {
        Http::preventStrayRequests();

        $targetUrl = 'https://n8n.example.com/webhook/my-flow';
        $row = WebhookTarget::create([
            'bot_id' => '111111',
            'target_url' => $targetUrl,
            'route_secret' => str_repeat('a', 32),
            'telegram_secret' => str_repeat('b', 64),
            'active' => true,
        ]);

        $hookUrl = rtrim((string) config('app.url'), '/')."/{$this->prefix}/hook/111111/{$row->route_secret}";

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/getWebhookInfo" => Http::response([
                'ok' => true,
                'result' => [
                    'url' => $hookUrl,
                    'has_custom_certificate' => false,
                    'pending_update_count' => 0,
                ],
            ], 200),
        ]);

        $response = $this->get("/{$this->prefix}/bot{$this->token}/getWebhookInfo");

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'result' => [
                    'url' => $targetUrl,
                ],
            ]);
    }

    public function test_t29_delete_webhook_deactivates_target(): void
    {
        Http::preventStrayRequests();

        $row = WebhookTarget::create([
            'bot_id' => '111111',
            'target_url' => 'https://n8n.example.com/hook',
            'route_secret' => str_repeat('x', 32),
            'telegram_secret' => str_repeat('y', 64),
            'active' => true,
        ]);

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/deleteWebhook" => Http::response([
                'ok' => true,
                'result' => true,
            ], 200),
        ]);

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/deleteWebhook");

        $response->assertStatus(200);
        $this->assertFalse($row->fresh()->active);
    }

    public function test_t30_inbound_hook_with_correct_secret_queues_job_and_returns_200(): void
    {
        Queue::fake();

        $row = WebhookTarget::create([
            'bot_id' => '111111',
            'target_url' => 'https://n8n.example.com/hook',
            'route_secret' => str_repeat('k', 32),
            'telegram_secret' => 'valid_secret_token_123',
            'active' => true,
        ]);

        $payload = json_encode(['update_id' => 99999, 'message' => ['text' => 'ping']]);

        $response = $this->call(
            'POST',
            "/{$this->prefix}/hook/111111/{$row->route_secret}",
            [],
            [],
            [],
            [
                'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'valid_secret_token_123',
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload
        );

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);

        Queue::assertPushed(ForwardWebhookUpdate::class, function (ForwardWebhookUpdate $job) use ($row, $payload) {
            return $job->targetId === $row->id && $job->payload === $payload;
        });
    }

    public function test_t31_inbound_hook_rejects_wrong_secret_or_unknown_route(): void
    {
        Queue::fake();

        $row = WebhookTarget::create([
            'bot_id' => '111111',
            'target_url' => 'https://n8n.example.com/hook',
            'route_secret' => str_repeat('m', 32),
            'telegram_secret' => 'correct_secret',
            'active' => true,
        ]);

        // Wrong secret header -> 403
        $res1 = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong_secret')
            ->postJson("/{$this->prefix}/hook/111111/{$row->route_secret}", ['update_id' => 1]);

        $res1->assertStatus(403)
            ->assertJson(['ok' => false]);

        // Unknown route secret -> 404
        $res2 = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'correct_secret')
            ->postJson("/{$this->prefix}/hook/111111/".str_repeat('z', 32), ['update_id' => 1]);

        $res2->assertStatus(404)
            ->assertJson(['ok' => false]);

        Queue::assertNothingPushed();
    }

    public function test_t32_forward_job_posts_payload_and_secret_to_target(): void
    {
        Http::preventStrayRequests();

        $row = WebhookTarget::create([
            'bot_id' => '111111',
            'target_url' => 'https://n8n.example.com/webhook/test',
            'target_secret' => 'my_target_secret_token',
            'route_secret' => str_repeat('t', 32),
            'telegram_secret' => str_repeat('s', 64),
            'active' => true,
        ]);

        $payload = json_encode(['update_id' => 1234, 'message' => ['text' => 'hello']]);

        Http::fake([
            'https://n8n.example.com/webhook/test' => function (ClientRequest $request) use ($payload) {
                $this->assertSame('my_target_secret_token', $request->header('X-Telegram-Bot-Api-Secret-Token')[0]);
                $this->assertSame('application/json', $request->header('Content-Type')[0]);
                $this->assertSame($payload, $request->body());

                return Http::response(['status' => 'received'], 200);
            },
        ]);

        $job = new ForwardWebhookUpdate($row->id, $payload);
        $job->handle();

        Http::assertSentCount(1);
    }

    public function test_t33_forward_job_retries_on_5xx_and_does_not_retry_on_4xx(): void
    {
        Http::preventStrayRequests();

        $row = WebhookTarget::create([
            'bot_id' => '111111',
            'target_url' => 'https://n8n.example.com/webhook/retry-test',
            'route_secret' => str_repeat('r', 32),
            'telegram_secret' => str_repeat('s', 64),
            'active' => true,
        ]);

        // 500 causes throw (so queue worker can retry)
        Http::fake([
            'https://n8n.example.com/webhook/retry-test' => Http::response('Server Error', 500),
        ]);

        $job = new ForwardWebhookUpdate($row->id, '{"test":1}');

        $this->expectException(RequestException::class);
        $job->handle();
    }

    public function test_t33_forward_job_does_not_retry_on_404(): void
    {
        Http::preventStrayRequests();

        $row = WebhookTarget::create([
            'bot_id' => '111111',
            'target_url' => 'https://n8n.example.com/webhook/not-found',
            'route_secret' => str_repeat('r', 32),
            'telegram_secret' => str_repeat('s', 64),
            'active' => true,
        ]);

        // 404 does NOT throw (final rejection, no retry)
        Http::fake([
            'https://n8n.example.com/webhook/not-found' => Http::response('Not Found', 404),
        ]);

        $job = new ForwardWebhookUpdate($row->id, '{"test":1}');
        $job->handle();

        Http::assertSentCount(1);
    }
}
