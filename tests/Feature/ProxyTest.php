<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProxyTest extends TestCase
{
    private string $prefix;

    private string $token = '111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = (string) config('relay.path_prefix');
    }

    public function test_t1_post_json_send_message_forwards_raw_body_and_returns_status(): void
    {
        Http::preventStrayRequests();

        $payload = ['chat_id' => 12345, 'text' => 'Hello World'];

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/sendMessage" => function (ClientRequest $request) use ($payload) {
                $this->assertSame('application/json', $request->header('Content-Type')[0]);
                $this->assertSame(json_encode($payload), $request->body());

                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 999, 'text' => 'Hello World'],
                ], 200, ['Content-Type' => 'application/json']);
            },
        ]);

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/sendMessage", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'result' => ['message_id' => 999, 'text' => 'Hello World'],
            ]);
    }

    public function test_t2_post_form_urlencoded_send_message_forwards_raw_body(): void
    {
        Http::preventStrayRequests();

        $rawBody = 'chat_id=12345&text=Hello+Form';

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/sendMessage" => function (ClientRequest $request) use ($rawBody) {
                $this->assertStringStartsWith('application/x-www-form-urlencoded', $request->header('Content-Type')[0]);
                $this->assertSame($rawBody, $request->body());

                return Http::response(['ok' => true, 'result' => ['message_id' => 1000]], 200);
            },
        ]);

        $response = $this->call(
            'POST',
            "/{$this->prefix}/bot{$this->token}/sendMessage",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $rawBody
        );

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_t3_multipart_send_photo_with_file_and_fields(): void
    {
        Http::preventStrayRequests();

        $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/sendPhoto" => function (ClientRequest $request) {
                $this->assertTrue($request->isMultipart());
                $fields = collect($request->data())->keyBy('name');

                $this->assertTrue($fields->has('photo'));
                $this->assertSame('photo.jpg', $fields['photo']['filename']);
                $this->assertSame('A nice photo', $fields['caption']['contents']);
                $this->assertSame('{"inline_keyboard":[]}', $fields['reply_markup']['contents']);

                return Http::response(['ok' => true, 'result' => ['message_id' => 1001]], 200);
            },
        ]);

        $response = $this->call(
            'POST',
            "/{$this->prefix}/bot{$this->token}/sendPhoto",
            [
                'caption' => 'A nice photo',
                'reply_markup' => '{"inline_keyboard":[]}',
            ],
            [],
            ['photo' => $file]
        );

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }

    public function test_t4_get_get_me_with_query_string(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/getMe?x=1" => Http::response([
                'ok' => true,
                'result' => ['id' => 111111, 'is_bot' => true, 'first_name' => 'TestBot'],
            ], 200),
        ]);

        $response = $this->get("/{$this->prefix}/bot{$this->token}/getMe?x=1");

        $response->assertStatus(200)
            ->assertJson(['ok' => true, 'result' => ['id' => 111111]]);
    }

    public function test_t5_telegram_returns_400_passes_through_unchanged(): void
    {
        Http::preventStrayRequests();

        $errorBody = [
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: chat not found',
        ];

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/sendMessage" => Http::response($errorBody, 400),
        ]);

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/sendMessage", [
            'chat_id' => 999999999,
            'text' => 'Hi',
        ]);

        $response->assertStatus(400)
            ->assertExactJson($errorBody);
    }

    public function test_t6_telegram_returns_429_passed_through_without_retry(): void
    {
        Http::preventStrayRequests();

        $callCount = 0;
        Http::fake([
            "https://api.telegram.org/bot{$this->token}/sendMessage" => function () use (&$callCount) {
                $callCount++;

                return Http::response([
                    'ok' => false,
                    'error_code' => 429,
                    'description' => 'Too Many Requests: retry after 30',
                    'parameters' => ['retry_after' => 30],
                ], 429);
            },
        ]);

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/sendMessage", ['text' => 'Hi']);

        $response->assertStatus(429)
            ->assertJson([
                'ok' => false,
                'error_code' => 429,
                'parameters' => ['retry_after' => 30],
            ]);

        $this->assertSame(1, $callCount);
    }

    public function test_t7_connect_failure_retries_and_returns_502(): void
    {
        Http::preventStrayRequests();

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 7: Failed to connect to api.telegram.org');
        });

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/sendMessage", ['text' => 'Hi']);

        $response->assertStatus(502)
            ->assertJson([
                'ok' => false,
                'error_code' => 502,
                'description' => 'Relay: Upstream unreachable',
            ]);

        $this->assertSame(3, $attempts);
    }

    public function test_t8_timeout_fails_with_504_in_single_attempt(): void
    {
        Http::preventStrayRequests();

        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');
        });

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/sendMessage", ['text' => 'Hi']);

        $response->assertStatus(504)
            ->assertJson([
                'ok' => false,
                'error_code' => 504,
                'description' => 'Relay: Upstream timeout',
            ]);

        $this->assertSame(1, $attempts);
    }

    public function test_t9_relay_key_query_param_is_stripped_from_upstream_url(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/getMe?foo=bar" => function (ClientRequest $request) {
                $this->assertFalse(str_contains($request->url(), 'relay_key'));

                return Http::response(['ok' => true], 200);
            },
        ]);

        $response = $this->get("/{$this->prefix}/bot{$this->token}/getMe?foo=bar&relay_key=secret_relay_key");

        $response->assertStatus(200);
    }

    public function test_t10_method_name_in_uppercase_is_forwarded_as_given(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            "https://api.telegram.org/bot{$this->token}/SENDMESSAGE" => Http::response(['ok' => true], 200),
        ]);

        $response = $this->postJson("/{$this->prefix}/bot{$this->token}/SENDMESSAGE", ['text' => 'hi']);

        $response->assertStatus(200)
            ->assertJson(['ok' => true]);
    }
}
