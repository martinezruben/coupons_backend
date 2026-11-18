<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppContractTest extends TestCase
{
    use RefreshDatabase;

    private function setup_(int $dispensers, string $role = 'MANAGER'): array
    {
        $loc = Location::factory()->create(['is_active' => true, 'dispensers' => $dispensers]);
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => $loc->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);
        $user = User::factory()->create(['role' => $role, 'location_id' => $loc->id]);
        Sanctum::actingAs($user, $user->couponAbilities());
        DB::table('coupons')->insert(['coupon_number' => 'A', 'coupon_token' => 'tok-A', 'coupon_active' => 1, 'coupon_amount' => '500.00', 'coupon_balance' => '500.00', 'coupon_expire_date' => now()->addYear()->toDateString()]);

        return [$loc, $device, $user];
    }

    private function pay(Device $device, array $extra = [], string $key = 'key-12345678')
    {
        return $this->postJson('/api/transaction', ['amount' => 10, 'coupons' => ['A'], 'tokens' => ['tok-A']] + $extra, ['Device-Fingerprint' => $device->device_fingerprint, 'Idempotency-Key' => $key]);
    }

    public function test_a_location_with_zero_dispensers_is_returned_as_zero(): void
    {
        $loc = Location::factory()->create(['is_active' => true, 'dispensers' => 0]);
        $this->assertSame(0, $loc->toApi()['dispensers']);

        User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'location_id' => $loc->id]);
        $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'])
            ->assertOk()->assertJsonPath('dispensers', 0)->assertJsonPath('location.dispensers', 0);
    }

    public function test_the_dispenser_is_validated_stored_and_returned_by_transaction(): void
    {
        config(['coupons.require_idempotency_key' => true, 'coupons.require_device_binding' => true]);
        [, $device] = $this->setup_(4);

        $this->pay($device, ['dispenser' => 3])->assertOk()->assertJsonPath('dispenser', 3);
        $this->assertDatabaseHas('coupon_transactions', ['coupon_number' => 'A', 'dispenser' => 3]);

        $this->pay($device, ['dispenser' => 5], 'key-aaaaaaaa')->assertStatus(422)->assertJsonPath('code', 'invalid_dispenser')->assertJsonPath('dispensers', 4);
        $this->pay($device, ['dispenser' => 21], 'key-bbbbbbbb')->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        // Sin surtidor: opcional salvo que se exija
        $this->pay($device, [], 'key-cccccccc')->assertOk()->assertJsonPath('dispenser', null);
        config(['coupons.require_dispenser' => true]);
        $this->pay($device, [], 'key-dddddddd')->assertStatus(422)->assertJsonPath('code', 'dispenser_required');
    }

    public function test_a_location_without_dispensers_ignores_the_dispenser(): void
    {
        config(['coupons.require_dispenser' => true]);
        [, $device] = $this->setup_(0);

        $this->pay($device, ['dispenser' => 2])->assertOk()->assertJsonPath('dispenser', null);
        $this->assertDatabaseHas('coupon_transactions', ['coupon_number' => 'A', 'dispenser' => null]);
    }

    public function test_minimum_app_version_returns_426(): void
    {
        [, $device] = $this->setup_(2);
        $send = fn (?string $version) => $this->postJson('/api/transaction', ['amount' => 1, 'coupons' => ['A'], 'tokens' => ['tok-A']], array_filter(['App-Version' => $version, 'Device-Fingerprint' => $device->device_fingerprint]));

        // Sin versión mínima no se bloquea nada
        $send('0.0.1')->assertOk();

        ($p = SystemParameter::query()->first()) ? $p->update(['min_app_version' => '1.1.0']) : SystemParameter::factory()->create(['min_app_version' => '1.1.0']);
        $send('1.0.4')->assertStatus(426)->assertJsonPath('code', 'app_update_required')->assertJsonPath('min_app_version', '1.1.0')->assertJsonPath('app_version', '1.0.4');
        $send(null)->assertStatus(426);
        $send('1.1.0')->assertOk();
        $send('1.2.0-debug')->assertOk();
        $this->postJson('/api/login', ['username' => 'x', 'password' => 'y'], ['App-Version' => '1.0.0'])->assertStatus(426);

        config(['coupons.enforce_min_app_version' => false]);
        $send('1.0.4')->assertOk();
    }

    public function test_reverse_topup_and_restore_contract(): void
    {
        config(['coupons.restore_requires_approval' => false]);
        [, $device] = $this->setup_(1);
        $h = ['Device-Fingerprint' => $device->device_fingerprint];

        // Un MANAGER distinto al que cobró revierte; aquí el cobro lo hace otro usuario
        $this->pay($device)->assertOk();
        $tx = DB::table('coupon_transactions')->first();
        DB::table('coupon_transactions')->where('transaction_id', $tx->transaction_id)->update(['user_id' => (string) Str::uuid()]);

        $this->postJson("/api/transactions/{$tx->transaction_id}/reverse", ['reason' => 'Cobro duplicado'], $h)->assertOk()
            ->assertJsonStructure(['transaction_id', 'original_transaction_id', 'reversal_transaction_id', 'date', 'coupon_number', 'balance'])
            ->assertJsonPath('original_transaction_id', $tx->transaction_id)->assertJsonPath('balance', '500.00');

        $this->postJson('/api/topup', ['coupon_number' => 'A', 'coupon_token' => 'tok-A', 'amount' => 20], $h + ['Idempotency-Key' => 'topup-12345'])->assertOk()
            ->assertJsonStructure(['coupon_number', 'date', 'topup_amount', 'balance', 'transaction_id'])->assertJsonPath('balance', '520.00');

        $this->as_admin();
        $this->postJson('/api/restore', ['coupon_number' => 'A', 'coupon_amount' => 100, 'reason' => 'Corrección de saldo'])->assertOk()
            ->assertJsonStructure(['coupon_number', 'date', 'balance'])->assertJsonPath('balance', '100.00');

        config(['coupons.restore_requires_approval' => true]);
        $this->postJson('/api/restore', ['coupon_number' => 'A', 'coupon_amount' => 50, 'reason' => 'Corrección de saldo'])->assertStatus(202)
            ->assertJsonStructure(['coupon_number', 'status', 'request_id'])->assertJsonPath('status', 'pending_approval');
    }

    private function as_admin(): void
    {
        $u = User::factory()->create(['role' => 'ADMIN']);
        Sanctum::actingAs($u, $u->couponAbilities());
    }
}
