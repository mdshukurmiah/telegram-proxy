<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RelayLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LoggingTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix;

    private string $token = '111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = (string) config('relay.path_prefix');
    }

    public function test_t23_proxied_call_writes_one_relay_logs_row_without_body(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/sendMessage" => Http::response([
                'ok' => true,
                'result' => ['message_id' => 12345],
            ], 200),
        ]);

        $this->postJson("/{$this->prefix}/bot{$this->token}/sendMessage", [
            'chat_id' => 12345,
            'text' => 'Confidential secret message text',
        ])->assertStatus(200);

        $this->assertSame(1, RelayLog::count());

        $log = RelayLog::first();
        $this->assertNotNull($log);
        $this->assertSame('111111', $log->bot_id);
        $this->assertSame('sendMessage', $log->method);
        $this->assertSame(200, $log->http_status);
        $this->assertGreaterThanOrEqual(0, $log->duration_ms);
        $this->assertNull($log->error);

        // Verify body is never stored in DB schema
        $this->assertFalse(Schema::hasColumn('relay_logs', 'body'));
        $this->assertFalse(Schema::hasColumn('relay_logs', 'request_body'));
        $this->assertFalse(Schema::hasColumn('relay_logs', 'response_body'));
        $this->assertFalse(Schema::hasColumn('relay_logs', 'payload'));
    }
}
