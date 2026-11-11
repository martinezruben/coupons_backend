<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationUserLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_with_valid_fingerprint_and_license_key(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'A', 'is_active' => true]);

        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-login-ok',
            'is_enabled' => true,
        ]);

        $license = License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);
        $this->assertSame($license->id, $license->license_key);

        $this->postJson('/api/auth/login', [
            'device_fingerprint' => 'fp-login-ok',
            'license_key' => $license->license_key,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'license_key', 'location_id', 'location_name'])
            ->assertJsonPath('license_key', $license->license_key)
            ->assertJsonPath('location_id', $location->id)
            ->assertJsonPath('location_name', 'A');
    }

    public function test_login_accepts_license_key_header(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'B', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-header',
            'is_enabled' => true,
        ]);
        $license = License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $this->withHeaders([
            'Device-Fingerprint' => 'fp-header',
            'License-Key' => $license->license_key,
        ])->postJson('/api/auth/login', [])
            ->assertOk()
            ->assertJsonPath('license_key', $license->license_key);
    }

    public function test_login_fails_with_unknown_fingerprint(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'C', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-x',
            'is_enabled' => true,
        ]);
        $license = License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/auth/login', [
            'device_fingerprint' => 'no-existe',
            'license_key' => $license->license_key,
        ])->assertStatus(422);
    }

    public function test_login_fails_when_license_key_belongs_to_another_device(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'D', 'is_active' => true]);
        $deviceA = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-a',
            'is_enabled' => true,
        ]);
        $deviceB = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-b',
            'is_enabled' => true,
        ]);
        $licenseB = License::create([
            'device_id' => $deviceB->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/auth/login', [
            'device_fingerprint' => 'fp-a',
            'license_key' => $licenseB->license_key,
        ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Licencia no encontrada para este dispositivo.']);
    }
}
