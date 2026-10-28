<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use RuntimeException;
use Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function test_t22_production_boot_with_short_prefix_throws_runtime_exception(): void
    {
        $this->app['env'] = 'production';
        config(['relay.path_prefix' => 'short']);
        config(['relay.allowed_bot_ids' => ['111111']]);

        $provider = new AppServiceProvider($this->app);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RELAY_PATH_PREFIX too short');

        $provider->boot();
    }

    public function test_t22_production_boot_with_empty_allowed_bot_ids_throws_runtime_exception(): void
    {
        $this->app['env'] = 'production';
        config(['relay.path_prefix' => 'r/a_very_long_valid_prefix_16+']);
        config(['relay.allowed_bot_ids' => []]);

        $provider = new AppServiceProvider($this->app);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RELAY_ALLOWED_BOT_IDS empty');

        $provider->boot();
    }
}
