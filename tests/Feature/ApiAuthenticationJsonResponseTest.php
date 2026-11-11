<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthenticationJsonResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_returns_json_401_without_redirect(): void
    {
        $response = $this->getJson('/api/sync/pull?device_fingerprint=test-fp');

        $response->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('error', 'unauthenticated')
            ->assertJsonStructure(['message', 'error']);

        $this->assertStringContainsString('Authorization', $response->json('message'));
    }
}
