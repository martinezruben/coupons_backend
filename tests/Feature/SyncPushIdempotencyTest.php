<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPushIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_upserts_by_device_and_external_id(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-sync-1',
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

        $token = $device->createToken('sync-test')->plainTextToken;

        $payload = [
            'transactions' => [[
                'external_id' => 'ext-001',
                'user_id' => $user->id,
                'turn_number' => 1,
                'status' => 'PAID',
                'total' => 100,
                'occurred_at' => now()->toDateTimeString(),
                'items' => [],
                'payments' => [['payment_method' => 'CASH', 'amount' => 100]],
            ]],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/sync/push', $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'stored' => 1]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/sync/push', $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'stored' => 0]);

        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_push_accepts_occurred_at_as_unix_milliseconds(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-sync-ms',
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

        $token = $device->createToken('sync-ms')->plainTextToken;

        $ms = '1775320054123';

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/sync/push', [
                'transactions' => [[
                    'external_id' => 'ext-ms-1',
                    'user_id' => $user->id,
                    'turn_number' => 1,
                    'status' => 'PAID',
                    'total' => 10,
                    'occurred_at' => $ms,
                    'items' => [],
                    'payments' => [],
                ]],
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'stored' => 1]);

        $row = Transaction::query()->where('external_id', 'ext-ms-1')->first();
        $this->assertNotNull($row);
        $this->assertSame(
            (int) floor(((int) $ms) / 1000),
            (int) $row->occurred_at->utc()->timestamp
        );
    }

    public function test_push_rejects_user_id_that_is_not_uuid_or_unknown_user(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-sync-uid',
            'is_enabled' => true,
        ]);

        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $token = $device->createToken('sync-uid')->plainTextToken;

        $badId = 'demo_ide0e619b5-b646-4e90-9387-132773de7dfc';

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/sync/push', [
                'transactions' => [[
                    'external_id' => 'ext-bad-user',
                    'user_id' => $badId,
                    'turn_number' => 1,
                    'status' => 'PAID',
                    'total' => 10,
                    'occurred_at' => now()->toIso8601String(),
                    'items' => [],
                    'payments' => [],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transactions.0.user_id']);

        $validButMissing = (string) Str::uuid();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/sync/push', [
                'transactions' => [[
                    'external_id' => 'ext-missing-user',
                    'user_id' => $validButMissing,
                    'turn_number' => 1,
                    'status' => 'PAID',
                    'total' => 10,
                    'occurred_at' => now()->toIso8601String(),
                    'items' => [],
                    'payments' => [],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transactions.0.user_id']);
    }

    public function test_push_accepts_shift_id_as_free_text_from_client(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-sync-shift',
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

        $token = $device->createToken('sync-shift')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/sync/push', [
                'transactions' => [[
                    'external_id' => 'ext-shift-label',
                    'shift_id' => 'T1',
                    'user_id' => $user->id,
                    'turn_number' => 1,
                    'status' => 'PAID',
                    'total' => 10,
                    'occurred_at' => now()->toIso8601String(),
                    'items' => [],
                    'payments' => [],
                ]],
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'stored' => 1]);

        $row = Transaction::query()->where('external_id', 'ext-shift-label')->first();
        $this->assertNotNull($row);
        $this->assertSame('T1', $row->shift_id);
    }
}
