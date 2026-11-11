<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiRequestLogSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_login_log_keeps_no_token_or_license_key(): void
    {
        $device = Device::factory()->create(['device_fingerprint' => 'fp-log', 'is_enabled' => true]);
        $license = License::factory()->create(['device_id' => $device->id]);

        $response = $this->withHeader('Device-Fingerprint', 'fp-log')
            ->postJson('/api/auth/login', ['license_key' => $license->license_key])
            ->assertOk();

        $log = ApiRequestLog::query()->latest()->firstOrFail();
        $stored = $log->parameters.' '.$log->response_summary;

        $this->assertStringNotContainsString($license->license_key, $stored);
        $this->assertStringNotContainsString((string) $response->json('token'), $stored);
        $this->assertStringContainsString('fp-log', $log->parameters);
        $this->assertSame('***', json_decode($log->response_summary, true)['token']);
    }

    public function test_migration_scrubs_logs_saved_before_the_fix(): void
    {
        $id = (string) Str::uuid();
        DB::table('api_request_logs')->insert([
            'id' => $id,
            'method' => 'POST',
            'path' => '/api/auth/login',
            'parameters' => json_encode(['license_key' => 'LK-123', 'device_fingerprint' => 'fp']),
            'response_summary' => '{"token":"9|abcdef","license_key":"LK-123","padding":"xxxx…',
            'response_status' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_09_28_000004_scrub_credentials_from_api_request_logs.php');
        $migration->up();

        $row = DB::table('api_request_logs')->where('id', $id)->first();
        $this->assertStringNotContainsString('LK-123', $row->parameters);
        $this->assertStringContainsString('fp', $row->parameters);
        $this->assertSame('[depurado: contenía credenciales]', $row->response_summary);
    }
}
