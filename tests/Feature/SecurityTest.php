<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix;

    private string $token = '111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = (string) config('relay.path_prefix');
    }

    public function test_t11_wrong_prefix_returns_404_with_zero_upstream_calls(): void
    {
        Http::preventStrayRequests();

        $response = $this->get('/wrong_prefix/bot'.$this->token.'/getMe');

        $response->assertStatus(404);
        $this->assertSame('', $response->getContent());
    }

    public function test_t12_bot_id_not_allowlisted_returns_403_telegram_shaped_with_zero_upstream_calls(): void
    {
        Http::preventStrayRequests();

        $unauthorizedToken = '999999:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
        $response = $this->get("/{$this->prefix}/bot{$unauthorizedToken}/getMe");

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Relay: Bot not allowed on this relay',
            ]);
    }

    public function test_t13_malformed_token_returns_404(): void
    {
        Http::preventStrayRequests();

        // Too short token part or bad format
        $response = $this->get("/{$this->prefix}/bot123:short/getMe");

        $response->assertStatus(404);
        $this->assertSame('', $response->getContent());
    }

    public function test_t14_relay_key_validation(): void
    {
        Http::preventStrayRequests();

        config(['relay.relay_key' => 'secret_pass_123']);

        // Missing key -> 401
        $response = $this->get("/{$this->prefix}/bot{$this->token}/getMe");
        $response->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Relay: Invalid relay key',
            ]);

        // Wrong key -> 401
        $response = $this->withHeader('X-Relay-Key', 'wrong_pass')
            ->get("/{$this->prefix}/bot{$this->token}/getMe");
        $response->assertStatus(401);

        // Correct key header -> 200, and X-Relay-Key is NOT sent upstream
        Http::fake([
            "https://api.telegram.org/bot{$this->token}/getMe" => function (ClientRequest $request) {
                $this->assertFalse($request->hasHeader('X-Relay-Key'));

                return Http::response(['ok' => true], 200);
            },
        ]);

        $response = $this->withHeader('X-Relay-Key', 'secret_pass_123')
            ->get("/{$this->prefix}/bot{$this->token}/getMe");

        $response->assertStatus(200);
    }

    public function test_t15_ip_allowlist_blocks_unauthorized_ip_but_hook_exempt(): void
    {
        Http::preventStrayRequests();

        config(['relay.allowed_ips' => ['10.0.0.1']]);

        // Request from unauthorized IP to bot route -> 403
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->get("/{$this->prefix}/bot{$this->token}/getMe");

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Relay: IP not allowed',
            ]);

        // Inbound hook route is exempt from IP allowlist (SR-4)
        $hookResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->postJson("/{$this->prefix}/hook/111111/12345678901234567890123456789012");

        // Should NOT be 403 IP not allowed; target not found -> 404
        $this->assertNotSame(403, $hookResponse->getStatusCode());
        $hookResponse->assertStatus(404);
    }

    public function test_t16_rate_limit_exceeded_returns_429_with_retry_after(): void
    {
        Http::preventStrayRequests();

        config(['relay.rate_limit' => 2]);

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/getMe" => Http::response(['ok' => true], 200),
        ]);

        $this->get("/{$this->prefix}/bot{$this->token}/getMe")->assertStatus(200);
        $this->get("/{$this->prefix}/bot{$this->token}/getMe")->assertStatus(200);

        $third = $this->get("/{$this->prefix}/bot{$this->token}/getMe");
        $third->assertStatus(429)
            ->assertJson([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Relay: Relay rate limit',
            ])
            ->assertJsonStructure(['parameters' => ['retry_after']]);
    }

    public function test_t17_logs_mask_full_token(): void
    {
        $logFile = storage_path('logs/laravel-'.date('Y-m-d').'.log');
        File::delete($logFile);

        Log::info("Handling request for token {$this->token}");

        $this->assertFileExists($logFile);
        $content = File::get($logFile);

        $this->assertStringContainsString('111111:****', $content);
        $this->assertStringNotContainsString('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $content);
    }
}
