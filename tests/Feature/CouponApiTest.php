<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CouponApiTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role = 'CASHIER'): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user, $user->couponAbilities());

        return $user;
    }

    private function qr(string $number, string $balance = '50.00', array $extra = []): void
    {
        DB::table('coupons')->insert(array_merge([
            'coupon_number' => $number, 'coupon_token' => 'tok-'.$number, 'coupon_active' => 1,
            'coupon_amount' => '100.00', 'coupon_balance' => $balance,
            'coupon_expire_date' => now()->addYear()->toDateString(),
        ], $extra));
    }

    private function bar(string $number, string $balance = '30.00'): void
    {
        DB::table('coupons_codbar')->insert([
            'coupon_number' => $number, 'coupon_active' => 1, 'coupon_amount' => '30.00',
            'coupon_balance' => $balance, 'coupon_expire_date' => now()->addYear()->toDateString(),
        ]);
    }

    private function balanceOf(string $table, string $number): string
    {
        return number_format((float) DB::table($table)->where('coupon_number', $number)->value('coupon_balance'), 2, '.', '');
    }

    private function deviceWithLicense(): Device
    {
        $device = Device::factory()->create(['is_enabled' => true]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);

        return $device;
    }

    // ---- Cobro

    public function test_redeem_spreads_amount_across_coupons(): void
    {
        $user = $this->as();
        $this->qr('A', '30.00');
        $this->qr('B', '50.00');

        $this->postJson('/api/transaction', ['amount' => 60.5, 'coupons' => ['A', 'B'], 'tokens' => ['tok-A', 'tok-B']])
            ->assertOk()
            ->assertJsonPath('coupons.0.transaction_amount', '30.00')
            ->assertJsonPath('coupons.1.transaction_amount', '30.50')
            ->assertJsonPath('sumbalance', '80.00');

        $this->assertSame('0.00', $this->balanceOf('coupons', 'A'));
        $this->assertSame('19.50', $this->balanceOf('coupons', 'B'));
        $this->assertDatabaseCount('coupon_transactions', 2);
        $this->assertDatabaseHas('coupon_transactions', ['coupon_number' => 'A', 'user_id' => $user->id, 'coupon_type' => 'QRCODE']);
    }

    public function test_redeem_rejects_bad_input(): void
    {
        $this->as();
        $this->qr('A');

        foreach ([
            ['amount' => -5, 'coupons' => ['A'], 'tokens' => ['tok-A']],
            ['amount' => 0, 'coupons' => ['A'], 'tokens' => ['tok-A']],
            ['amount' => 'abc', 'coupons' => ['A'], 'tokens' => ['tok-A']],
            ['amount' => 1.005, 'coupons' => ['A'], 'tokens' => ['tok-A']],
            ['amount' => 5, 'coupons' => ['A', 'A'], 'tokens' => ['tok-A', 'tok-A']],
            ['amount' => 5, 'coupons' => [], 'tokens' => []],
            ['amount' => 5],
        ] as $body) {
            $this->postJson('/api/transaction', $body)->assertStatus(422);
        }
        $this->assertSame('50.00', $this->balanceOf('coupons', 'A'));
    }

    public function test_insufficient_balance_keeps_legacy_shape_and_changes_nothing(): void
    {
        $this->as();
        $this->qr('A', '10.00');

        $this->postJson('/api/transaction', ['amount' => 20, 'coupons' => ['A'], 'tokens' => ['tok-A']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'Insufficient balance')
            ->assertJsonPath('balance', '10.00');

        $this->assertSame('10.00', $this->balanceOf('coupons', 'A'));
    }

    public function test_unknown_coupon_wrong_token_and_missing_token_look_the_same(): void
    {
        $this->as();
        $this->qr('A');

        foreach ([
            ['amount' => 5, 'coupons' => ['ZZZ'], 'tokens' => ['x']],
            ['amount' => 5, 'coupons' => ['A'], 'tokens' => ['wrong']],
            ['amount' => 5, 'coupons' => ['A']],
        ] as $body) {
            $this->postJson('/api/transaction', $body)->assertStatus(404)->assertJsonPath('error', 'Coupon not found');
        }
        $this->assertSame('50.00', $this->balanceOf('coupons', 'A'));
    }

    public function test_inactive_and_expired_coupons_are_rejected(): void
    {
        $this->as();
        $this->qr('OFF', '50.00', ['coupon_active' => 0]);
        $this->qr('OLD', '50.00', ['coupon_expire_date' => now()->subDays(2)->toDateString()]);

        foreach (['OFF', 'OLD'] as $n) {
            $this->postJson('/api/transaction', ['amount' => 5, 'coupons' => [$n], 'tokens' => ['tok-'.$n]])->assertStatus(422);
            $this->getJson("/api/balance?coupon_number=$n&coupon_token=tok-$n")->assertOk()->assertJsonPath('active', false);
        }
    }

    public function test_balance_requires_valid_token_for_qr_only(): void
    {
        $this->as();
        $this->qr('A');
        $this->bar('B1');

        $this->getJson('/api/balance?coupon_number=A')->assertStatus(422);
        $this->getJson('/api/balance?coupon_number=A&coupon_token=bad')->assertStatus(404);
        $this->getJson('/api/balance?coupon_number=A&coupon_token=tok-A')->assertOk()->assertJsonPath('balance', '50.00');
        $this->getJson('/api/balancecb?coupon_number=B1')->assertOk()->assertJsonPath('active', true);
    }

    public function test_barcode_redeem_works(): void
    {
        $this->as();
        $this->bar('B1', '30.00');

        $this->postJson('/api/transactioncb', ['amount' => 10, 'coupons' => ['B1']])
            ->assertOk()->assertJsonPath('coupons.0.balance', '20.00');
        $this->assertDatabaseHas('coupon_transactions', ['coupon_number' => 'B1', 'coupon_type' => 'CODBAR']);
    }

    public function test_errors_never_leak_internal_messages(): void
    {
        $this->as();
        $this->qr('A');
        config(['app.debug' => false]);
        DB::statement('DROP TABLE coupon_transactions');

        $res = $this->postJson('/api/transaction', ['amount' => 5, 'coupons' => ['A'], 'tokens' => ['tok-A']]);
        $res->assertStatus(500);
        $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
        $this->assertStringNotContainsString('coupon_transactions', $res->getContent());
    }

    // ---- Idempotencia

    public function test_idempotency_key_replays_instead_of_charging_twice(): void
    {
        $this->as();
        $this->qr('A', '50.00');
        $body = ['amount' => 10, 'coupons' => ['A'], 'tokens' => ['tok-A']];
        $h = ['Idempotency-Key' => 'abcdef123456'];

        $first = $this->postJson('/api/transaction', $body, $h)->assertOk();
        $second = $this->postJson('/api/transaction', $body, $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame('40.00', $this->balanceOf('coupons', 'A'));
        $this->assertDatabaseCount('coupon_transactions', 1);
        $this->postJson('/api/transaction', array_merge($body, ['amount' => 11]), $h)->assertStatus(422);
    }

    public function test_idempotency_key_can_be_made_mandatory(): void
    {
        config(['coupons.require_idempotency_key' => true]);
        $this->as();
        $this->qr('A');

        $this->postJson('/api/transaction', ['amount' => 5, 'coupons' => ['A'], 'tokens' => ['tok-A']])->assertStatus(400);
    }

    // ---- Permisos por rol

    public function test_restore_is_admin_only_validated_and_audited(): void
    {
        $this->qr('A', '10.00');
        $this->bar('B1', '5.00');
        $body = ['coupon_number' => 'A', 'coupon_amount' => 40, 'reason' => 'Error de caja'];

        foreach (['CASHIER', 'MANAGER'] as $role) {
            $this->as($role);
            $this->postJson('/api/restore', $body)->assertStatus(403);
            $this->postJson('/api/restorecb', $body)->assertStatus(403);
        }
        $this->assertSame('10.00', $this->balanceOf('coupons', 'A'));

        $admin = $this->as('ADMIN');
        $this->postJson('/api/restore', array_merge($body, ['coupon_amount' => -1]))->assertStatus(422);
        $this->postJson('/api/restore', array_merge($body, ['coupon_amount' => 100.01]))->assertStatus(422);
        $this->postJson('/api/restore', array_merge($body, ['reason' => '']))->assertStatus(422);
        $this->postJson('/api/restore', $body)->assertOk()->assertJsonPath('balance', '40.00');
        $this->postJson('/api/restorecb', ['coupon_number' => 'B1', 'coupon_amount' => 30, 'reason' => 'Ajuste'])->assertOk();

        $this->assertDatabaseHas('coupon_audit_logs', [
            'user_id' => $admin->id, 'action' => 'restore', 'coupon_number' => 'A', 'old_value' => '10.00', 'new_value' => '40.00',
        ]);
    }

    public function test_reverse_restores_balance_once_and_only_for_manager_or_admin(): void
    {
        $this->as('CASHIER');
        $this->qr('A', '50.00');
        $this->postJson('/api/transaction', ['amount' => 20, 'coupons' => ['A'], 'tokens' => ['tok-A']])->assertOk();
        $txId = DB::table('coupon_transactions')->value('transaction_id');
        $body = ['reason' => 'Cobro duplicado'];

        $this->postJson("/api/transactions/$txId/reverse", $body)->assertStatus(403);

        $manager = $this->as('MANAGER');
        $this->postJson("/api/transactions/$txId/reverse", ['reason' => ''])->assertStatus(422);
        $this->postJson("/api/transactions/$txId/reverse", $body)->assertOk()->assertJsonPath('balance', '50.00');
        $this->postJson("/api/transactions/$txId/reverse", $body)->assertStatus(409);
        $this->postJson('/api/transactions/9999/reverse', $body)->assertStatus(404);

        $this->assertSame('50.00', $this->balanceOf('coupons', 'A'));
        $this->assertDatabaseHas('coupon_transactions', ['reversed_of' => $txId, 'transaction_amount' => '-20.00']);
        $this->assertDatabaseHas('coupon_audit_logs', ['user_id' => $manager->id, 'action' => 'reverse']);

        $compId = DB::table('coupon_transactions')->where('reversed_of', $txId)->value('transaction_id');
        $this->postJson("/api/transactions/$compId/reverse", $body)->assertStatus(422);
    }

    public function test_cashier_has_only_read_and_redeem_abilities(): void
    {
        $this->assertSame(['coupon:read', 'coupon:redeem'], User::factory()->make(['role' => 'CASHIER'])->couponAbilities());
        $this->assertSame([], User::factory()->make(['role' => 'OTRO'])->couponAbilities());
    }

    // ---- Quién puede entrar

    public function test_unauthenticated_and_wrong_ability_are_blocked(): void
    {
        $this->getJson('/api/balancecb?coupon_number=X')->assertStatus(401);

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['coupon:read']);
        $this->postJson('/api/transactioncb', ['amount' => 5, 'coupons' => ['X']])->assertStatus(403);
    }

    public function test_device_tokens_cannot_use_coupon_routes(): void
    {
        $device = $this->deviceWithLicense();
        Sanctum::actingAs($device, ['*']);

        $this->getJson('/api/balancecb?coupon_number=X')->assertStatus(401);
    }

    public function test_deactivating_a_user_cuts_access_immediately(): void
    {
        $user = $this->as();
        $this->bar('B1');
        $this->getJson('/api/balancecb?coupon_number=B1')->assertOk();

        $user->update(['is_active' => false]);

        $this->getJson('/api/balancecb?coupon_number=B1')->assertStatus(401);
    }

    // ---- Dispositivo

    public function test_valid_device_is_recorded_on_the_transaction(): void
    {
        $this->as();
        $this->bar('B1');
        $device = $this->deviceWithLicense();

        $this->postJson('/api/transactioncb', ['amount' => 5, 'coupons' => ['B1']], ['Device-Fingerprint' => $device->device_fingerprint])->assertOk();

        $this->assertDatabaseHas('coupon_transactions', ['device_id' => $device->id, 'location_id' => $device->location_id]);
    }

    public function test_unknown_device_is_ignored_by_default_but_rejected_when_binding_is_required(): void
    {
        $this->as();
        $this->bar('B1');
        $headers = ['Device-Fingerprint' => 'no-existe'];

        $this->postJson('/api/transactioncb', ['amount' => 5, 'coupons' => ['B1']], $headers)->assertOk();
        $this->assertDatabaseHas('coupon_transactions', ['device_id' => null]);

        config(['coupons.require_device_binding' => true]);
        $this->postJson('/api/transactioncb', ['amount' => 5, 'coupons' => ['B1']], $headers)->assertStatus(403);
        $this->postJson('/api/transactioncb', ['amount' => 5, 'coupons' => ['B1']])->assertStatus(403);
    }

    public function test_disabled_device_is_not_accepted_when_binding_is_required(): void
    {
        config(['coupons.require_device_binding' => true]);
        $this->as();
        $this->bar('B1');
        $device = $this->deviceWithLicense();
        $device->update(['is_enabled' => false]);

        $this->postJson('/api/transactioncb', ['amount' => 5, 'coupons' => ['B1']], ['Device-Fingerprint' => $device->device_fingerprint])
            ->assertStatus(403);
    }
}
