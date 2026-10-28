<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FileProxyTest extends TestCase
{
    private string $prefix;

    private string $token = '111111:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = (string) config('relay.path_prefix');
    }

    public function test_t18_get_file_streams_upstream_body_and_content_type(): void
    {
        Http::preventStrayRequests();

        $fileContent = 'binary-image-data-here';

        Http::fake([
            "https://api.telegram.org/file/bot{$this->token}/photos/a.jpg" => Http::response(
                $fileContent,
                200,
                [
                    'Content-Type' => 'image/jpeg',
                    'Content-Length' => (string) strlen($fileContent),
                ]
            ),
        ]);

        $response = $this->get("/{$this->prefix}/file/bot{$this->token}/photos/a.jpg");

        $response->assertStatus(200);
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame($fileContent, $response->streamedContent());
    }

    public function test_t19_file_path_containing_parent_directory_returns_400(): void
    {
        Http::preventStrayRequests();

        $response = $this->get("/{$this->prefix}/file/bot{$this->token}/photos/../secret.txt");

        $response->assertStatus(400)
            ->assertJson([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Relay: Invalid path',
            ]);
    }
}
