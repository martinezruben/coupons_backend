<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceLicenseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rejected_without_active_license(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Store 1', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-1',
            'is_enabled' => true,
        ]);

        $license = License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDays(10),
            'valid_to' => now()->subDay(),
            'status' => 'EXPIRED',
        ]);

        $this->postJson('/api/auth/login', [
            'device_fingerprint' => 'fp-1',
            'license_key' => $license->license_key,
        ])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'La licencia no está activa o ha expirado. No se permite la sincronización.']);
    }
}
