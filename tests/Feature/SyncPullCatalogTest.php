<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPullCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_returns_catalog_json_shape(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-pull-1',
            'is_enabled' => true,
        ]);

        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create();
        $user->locations()->attach($location->id);

        $family = Family::create(['name' => 'Bebidas']);
        $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Gaseosas']);
        Product::create([
            'sku' => 'SKU-1',
            'name' => 'Cola',
            'subfamily_id' => $sub->id,
            'price' => 1.5,
            'tax_rate' => 12,
            'is_active' => true,
        ]);

        $token = $device->createToken('pull-test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]));

        $response->assertOk()
            ->assertJsonPath('data.license.deviceFingerprint', $device->device_fingerprint);

        $userRow = collect($response->json('data.users'))->firstWhere('id', $user->id);
        $this->assertNotNull($userRow);
        $this->assertSame(User::pinSha384FromPlain('password'), $userRow['pin']);

        $response->assertJsonStructure([
            'syncTimestamp',
            'data' => [
                'license' => ['deviceFingerprint', 'isActive', 'plan', 'expiresAt', 'updatedAt'],
                'users' => [
                    ['id', 'username', 'pin', 'role', 'isActive', 'updatedAt', 'deletedAt'],
                ],
                'families' => [
                    ['id', 'name', 'imageUrl', 'updatedAt', 'deletedAt'],
                ],
                'subfamilies' => [
                    ['id', 'familyId', 'name', 'updatedAt', 'deletedAt'],
                ],
                'products' => [
                    ['id', 'name', 'description', 'sku', 'codebar', 'imageUrl', 'familyName', 'categoria', 'subfamilyName', 'unitPrice', 'taxName', 'taxRate', 'isFavorite', 'updatedAt', 'deletedAt'],
                ],
                'paymentMethods' => [
                    ['id', 'name', 'type', 'isEnabled', 'updatedAt', 'deletedAt'],
                ],
            ],
        ]);
    }

    public function test_pull_requires_matching_device_fingerprint(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-pull-2',
            'is_enabled' => true,
        ]);

        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $token = $device->createToken('pull-test-2')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => 'huella-incorrecta']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['device_fingerprint']);
    }

    public function test_pull_accepts_last_sync_timestamp_as_unix_milliseconds(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-pull-ms',
            'is_enabled' => true,
        ]);

        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $token = $device->createToken('pull-ms')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query([
                'device_fingerprint' => $device->device_fingerprint,
                'lastSyncTimestamp' => '1775275592230',
            ]))
            ->assertOk()
            ->assertJsonStructure(['syncTimestamp', 'data']);
    }

    public function test_pull_accepts_device_fingerprint_via_header(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-pull-header',
            'is_enabled' => true,
        ]);

        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $token = $device->createToken('pull-hdr')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Device-Fingerprint', 'fp-pull-header')
            ->getJson('/api/sync/pull')
            ->assertOk();
    }
}
