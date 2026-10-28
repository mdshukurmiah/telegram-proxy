<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_shallow_health_returns_200_without_auth(): void
    {
        Http::preventStrayRequests();

        $response = $this->getJson('/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
            ])
            ->assertJsonStructure(['status', 'version']);
    }

    public function test_deep_health_when_telegram_reachable(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            config('relay.telegram_base') => Http::response('OK', 200),
        ]);

        $prefix = config('relay.path_prefix');
        $response = $this->getJson("/{$prefix}/health/deep");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'telegram' => 'reachable',
            ])
            ->assertJsonStructure(['status', 'telegram', 'latency_ms']);
    }

    public function test_deep_health_when_telegram_unreachable(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            config('relay.telegram_base') => fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
        ]);

        $prefix = config('relay.path_prefix');
        $response = $this->getJson("/{$prefix}/health/deep");

        $response->assertStatus(503)
            ->assertJson([
                'status' => 'degraded',
                'telegram' => 'unreachable',
            ]);
    }
}
