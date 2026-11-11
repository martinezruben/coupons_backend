<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\SyncLog;
use App\Models\SyncState;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Device, 1: string, 2: User} */
    private function pairedDevice(?Location $location = null): array
    {
        $location ??= Location::factory()->create(['is_active' => true]);
        $device = Device::factory()->create(['location_id' => $location->id, 'is_enabled' => true]);
        License::factory()->create([
            'device_id' => $device->id,
            'status' => 'ACTIVE',
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
        ]);
        $user = User::factory()->create();
        $user->locations()->attach($location->id);

        return [$device, $device->createToken('t')->plainTextToken, $user];
    }

    private function tx(User $user, string $externalId, string $status = 'PAID', array $extra = []): array
    {
        return array_merge([
            'external_id' => $externalId,
            'user_id' => $user->id,
            'turn_number' => 1,
            'status' => $status,
            'total' => 100,
            'occurred_at' => now()->toIso8601String(),
            'items' => [],
            'payments' => [],
        ], $extra);
    }

    public function test_stale_retry_cannot_downgrade_a_paid_transaction(): void
    {
        [, $token, $user] = $this->pairedDevice();
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/sync/push', ['transactions' => [$this->tx($user, 'ext-1', 'PAID')]], $headers)->assertOk();
        $this->postJson('/api/sync/push', ['transactions' => [$this->tx($user, 'ext-1', 'PENDING', ['total' => 5])]], $headers)->assertOk();

        $row = Transaction::where('external_id', 'ext-1')->first();
        $this->assertSame('PAID', $row->status);
        $this->assertEquals(100, $row->total);

        // Anular sí avanza el estado
        $this->postJson('/api/sync/push', ['transactions' => [$this->tx($user, 'ext-1', 'VOIDED')]], $headers)->assertOk();
        $this->assertSame('VOIDED', $row->fresh()->status);

        // y una VOIDED no se reabre
        $this->postJson('/api/sync/push', ['transactions' => [$this->tx($user, 'ext-1', 'PAID')]], $headers)->assertOk();
        $this->assertSame('VOIDED', $row->fresh()->status);
    }

    public function test_unknown_product_does_not_fail_the_whole_push(): void
    {
        [, $token, $user] = $this->pairedDevice();

        $item = ['product_id' => (string) Str::uuid(), 'product_name' => 'Cafe', 'qty' => 1, 'unit_price' => 50, 'line_total' => 50];

        $this->postJson('/api/sync/push', ['transactions' => [$this->tx($user, 'ext-p', 'PAID', ['items' => [$item]])]], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJson(['stored' => 1]);

        $line = TransactionItem::first();
        $this->assertNull($line->product_id);
        $this->assertSame('Cafe', $line->product_name);
    }

    public function test_non_numeric_line_values_are_rejected_with_422(): void
    {
        [, $token, $user] = $this->pairedDevice();

        $this->postJson('/api/sync/push', ['transactions' => [
            $this->tx($user, 'ext-bad', 'PAID', ['items' => [['product_name' => 'X', 'qty' => 'abc']]]),
        ]], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transactions.0.items.0.qty']);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_failed_push_is_logged_for_the_dashboard(): void
    {
        [$device, $token, $user] = $this->pairedDevice();

        $this->postJson('/api/sync/push', ['transactions' => [
            $this->tx($user, 'ext-bad', 'PAID', ['payments' => [['amount' => 'mucho']]]),
        ]], ['Authorization' => 'Bearer '.$token])->assertStatus(422);

        $log = SyncLog::where('device_id', $device->id)->first();
        $this->assertSame('FAILED', $log->status);
        $this->assertSame('PUSH', $log->operation);
        $this->assertNotEmpty($log->error_message);

        $state = SyncState::where('device_id', $device->id)->first();
        $this->assertNotNull($state->last_error_at);
    }

    public function test_reports_are_scoped_to_the_device_location(): void
    {
        [$deviceA, $tokenA] = $this->pairedDevice();
        $otherLocation = Location::factory()->create();

        Transaction::factory()->create(['location_id' => $deviceA->location_id, 'status' => 'PAID', 'total' => 100, 'occurred_at' => now()]);
        Transaction::factory()->create(['location_id' => $otherLocation->id, 'status' => 'PAID', 'total' => 900, 'occurred_at' => now()]);
        $headers = ['Authorization' => 'Bearer '.$tokenA];

        // Pedir otra localidad por query no amplía el alcance
        $this->getJson('/api/reports/dashboard?location_id='.$otherLocation->id, $headers)
            ->assertOk()
            ->assertJson(['sales_today' => 100, 'tickets_today' => 1]);

        $rows = $this->getJson('/api/reports/by-location', $headers)->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertSame($deviceA->location_id, $rows[0]['location_id']);
    }
}
