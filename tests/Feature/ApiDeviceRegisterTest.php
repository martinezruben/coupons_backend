<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Location;
use App\Support\DevicePairingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiDeviceRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_register_device_creates_device_and_license_with_valid_pairing_code(): void
    {
        $loc = Location::create([
            'id' => (string) Str::uuid(),
            'name' => 'Local demo',
            'is_active' => true,
        ]);

        $payload = DevicePairingToken::generateNew($loc->id);
        $code = $payload['code'];

        $response = $this->postJson('/api/auth/register-device', [
            'device_fingerprint' => 'android-fp-nuevo-001',
            'pairing_token' => $code,
        ]);

        $response->assertOk()
            ->assertJsonPath('location_id', $loc->id)
            ->assertJsonPath('location_name', 'Local demo')
            ->assertJsonStructure([
                'token',
                'license_key',
                'location_id',
                'location_name',
                'valid_from',
                'valid_to',
            ]);

        $this->assertDatabaseHas('devices', [
            'device_fingerprint' => 'android-fp-nuevo-001',
        ]);

        $device = Device::query()->where('device_fingerprint', 'android-fp-nuevo-001')->first();
        $this->assertNotNull($device?->registered_at);
    }

    public function test_register_device_rejects_wrong_pairing_code(): void
    {
        $loc = Location::create([
            'id' => (string) Str::uuid(),
            'name' => 'Local demo',
            'is_active' => true,
        ]);
        DevicePairingToken::generateNew($loc->id);

        $this->postJson('/api/auth/register-device', [
            'device_fingerprint' => 'fp-bad',
            'pairing_token' => '000000',
        ])->assertStatus(422);
    }

    public function test_register_device_returns_token_when_fingerprint_already_registered_re_enrollment(): void
    {
        $loc = Location::create([
            'id' => (string) Str::uuid(),
            'name' => 'Local demo',
            'is_active' => true,
        ]);

        $payload = DevicePairingToken::generateNew($loc->id);
        $first = $this->postJson('/api/auth/register-device', [
            'device_fingerprint' => 'fp-dup',
            'pairing_token' => $payload['code'],
        ]);
        $first->assertOk();
        $firstLicenseKey = $first->json('license_key');

        $device = Device::query()->where('device_fingerprint', 'fp-dup')->first();
        $registeredAtFirst = $device->registered_at->copy();

        $this->travel(5)->seconds();

        $p2 = DevicePairingToken::generateNew($loc->id);
        $second = $this->postJson('/api/auth/register-device', [
            'device_fingerprint' => 'fp-dup',
            'pairing_token' => $p2['code'],
        ]);
        $second->assertOk()
            ->assertJsonPath('license_key', $firstLicenseKey)
            ->assertJsonStructure([
                'token',
                'license_key',
                'location_id',
                'location_name',
                'valid_from',
                'valid_to',
            ]);

        $device->refresh();
        $this->assertTrue($device->registered_at->greaterThan($registeredAtFirst));
    }
}
