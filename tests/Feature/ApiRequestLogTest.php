<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiRequestLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_call_creates_request_log_row(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'L', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-log',
            'is_enabled' => true,
        ]);
        $license = License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $this->assertSame(0, ApiRequestLog::count());

        $this->postJson('/api/auth/login', [
            'device_fingerprint' => 'fp-log',
            'license_key' => $license->license_key,
        ])->assertOk();

        $this->assertSame(1, ApiRequestLog::count());
        $log = ApiRequestLog::first();
        $this->assertSame('POST', $log->method);
        $this->assertStringContainsString('auth/login', $log->path);
        $this->assertSame(200, $log->response_status);
        $this->assertSame($device->id, $log->device_id);
        $this->assertSame($location->id, $log->location_id);
        $this->assertSame('fp-log', $log->device_fingerprint);
        $this->assertNotNull($log->parameters);
    }
}
