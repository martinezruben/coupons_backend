<?php

namespace Tests\Feature;

use App\Models\AdjustmentRequest;
use App\Models\AdminUser;
use App\Models\CouponTransaction;
use App\Models\Location;
use App\Models\SecurityAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RedemptionControlsTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role = 'CASHIER'): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user, $user->couponAbilities());

        return $user;
    }

    private function qr(string $number, string $balance = '500.00'): void
    {
        DB::table('coupons')->insert([
            'coupon_number' => $number, 'coupon_token' => 'tok-'.$number, 'coupon_active' => 1,
            'coupon_amount' => '500.00', 'coupon_balance' => $balance, 'coupon_expire_date' => now()->addYear()->toDateString(),
        ]);
    }

    private function pay(string $number, $amount = 1): TestResponse
    {
        return $this->postJson('/api/transaction', ['amount' => $amount, 'coupons' => [$number], 'tokens' => ['tok-'.$number]]);
    }

    public function test_per_coupon_daily_limit_blocks_and_raises_an_alert(): void
    {
        config(['coupons.limits.per_coupon_daily_count' => 2]);
        $this->as();
        $this->qr('A');

        $this->pay('A')->assertOk();
        $this->pay('A')->assertOk();
        $this->pay('A')->assertStatus(429)->assertJsonPath('limit', 'per_coupon_daily_count');

        $this->assertSame(2, CouponTransaction::where('coupon_number', 'A')->count());
        $this->assertDatabaseHas('coupon_security_alerts', ['type' => 'limit_blocked', 'coupon_number' => 'A']);
    }

    public function test_user_and_large_amount_limits(): void
    {
        config(['coupons.limits.per_user_daily_amount' => '50', 'coupons.limits.large_redeem_threshold' => '30']);
        $this->as('CASHIER');
        $this->qr('A');

        $this->pay('A', 31)->assertStatus(403)->assertJsonPath('limit', 'large_redeem');
        $this->pay('A', 30)->assertOk();
        $this->pay('A', 25)->assertStatus(429)->assertJsonPath('limit', 'per_user_daily_amount');

        $this->as('MANAGER');
        config(['coupons.limits.per_user_daily_amount' => '0']);
        $this->pay('A', 31)->assertOk(); // el supervisor sí puede cobrar montos grandes
    }

    public function test_cashier_cannot_reverse_own_sale_but_a_manager_can_and_window_applies(): void
    {
        $cashier = $this->as('CASHIER');
        $this->qr('A');
        $this->pay('A', 10)->assertOk();
        $tx = CouponTransaction::first();

        // El cajero ni siquiera tiene la habilidad; el MANAGER que hizo el cobro tampoco puede revertirlo
        $manager = $this->as('MANAGER');
        $tx->update(['user_id' => $manager->id]);
        $this->postJson("/api/transactions/{$tx->transaction_id}/reverse", ['reason' => 'Error de captura'])->assertStatus(403);
        $this->assertDatabaseHas('coupon_security_alerts', ['type' => 'self_reverse_attempt']);

        // Otro MANAGER sí, dentro de la ventana
        $tx->update(['user_id' => $cashier->id]);
        $this->postJson("/api/transactions/{$tx->transaction_id}/reverse", ['reason' => 'Error de captura'])->assertOk();

        // Fuera de la ventana solo un ADMIN
        $this->pay('A', 5)->assertOk();
        $old = CouponTransaction::where('kind', 'REDEEM')->where('transaction_amount', '5.00')->first();
        $old->forceFill(['created_at' => now()->subHours(100), 'user_id' => $cashier->id])->save();
        $this->postJson("/api/transactions/{$old->transaction_id}/reverse", ['reason' => 'Error de captura'])->assertStatus(422);
        $this->as('ADMIN');
        $this->postJson("/api/transactions/{$old->transaction_id}/reverse", ['reason' => 'Error de captura'])->assertOk();
    }

    public function test_restore_waits_for_a_different_admin_to_approve(): void
    {
        config(['coupons.restore_requires_approval' => true]);
        $this->qr('A', '10.00');
        $this->as('ADMIN');

        $res = $this->postJson('/api/restore', ['coupon_number' => 'A', 'coupon_amount' => 80, 'reason' => 'Corrección de saldo'])->assertStatus(202)->assertJsonPath('status', 'pending_approval');
        $this->assertSame('10.00', number_format((float) DB::table('coupons')->where('coupon_number', 'A')->value('coupon_balance'), 2, '.', ''));

        $perm = fn (string ...$p) => collect($p)->each(fn ($n) => Permission::findOrCreate($n, 'admin'));
        $perm('coupon_adjustments.view', 'coupon_adjustments.approve');
        $approver = AdminUser::factory()->create();
        $approver->givePermissionTo('coupon_adjustments.view', 'coupon_adjustments.approve');

        $id = $res->json('request_id');
        $this->actingAs($approver, 'admin')->get(route('admin.adjustments.index'))->assertOk()->assertSee('Corrección de saldo');
        $this->post(route('admin.adjustments.approve', $id))->assertSessionHas('status');

        $this->assertSame('80.00', number_format((float) DB::table('coupons')->where('coupon_number', 'A')->value('coupon_balance'), 2, '.', ''));
        $this->assertSame(AdjustmentRequest::APPROVED, AdjustmentRequest::find($id)->status);
        $this->post(route('admin.adjustments.approve', $id))->assertSessionHasErrors('coupon'); // ya decidida
    }

    public function test_an_admin_cannot_approve_their_own_adjustment(): void
    {
        config(['coupons.restore_requires_approval' => true]);
        $this->qr('A', '10.00');
        $admin = AdminUser::factory()->create();
        Permission::findOrCreate('coupon_adjustments.view', 'admin');
        Permission::findOrCreate('coupon_adjustments.approve', 'admin');
        Permission::findOrCreate('coupons.adjust', 'admin');
        $admin->givePermissionTo('coupon_adjustments.view', 'coupon_adjustments.approve');

        $req = AdjustmentRequest::create([
            'coupon_type' => 'QRCODE', 'coupon_number' => 'A', 'current_balance' => '10.00', 'new_balance' => '90.00', 'reason' => 'Prueba',
            'requested_by_admin_id' => $admin->id, 'status' => 'PENDING',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.adjustments.approve', $req->id))->assertSessionHasErrors('coupon');
        $this->assertSame('10.00', number_format((float) DB::table('coupons')->where('coupon_number', 'A')->value('coupon_balance'), 2, '.', ''));

        $this->post(route('admin.adjustments.reject', $req->id), ['note' => 'No procede'])->assertSessionHas('status');
        $this->assertSame(AdjustmentRequest::REJECTED, $req->fresh()->status);
    }

    public function test_repeated_failed_lookups_and_two_locations_raise_alerts(): void
    {
        config(['coupons.alerts.failed_lookups' => 3]);
        Cache::flush();
        $this->as();
        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/balance?coupon_number=NOPE&coupon_token=x')->assertStatus(404);
        }
        $this->assertDatabaseHas('coupon_security_alerts', ['type' => 'failed_lookups', 'severity' => 'high']);

        $this->qr('B');
        $locA = Location::factory()->create()->id;
        $locB = Location::factory()->create()->id;
        $this->postJson('/api/transaction', ['amount' => 1, 'coupons' => ['B'], 'tokens' => ['tok-B'], 'location_id' => $locA])->assertOk();
        $this->postJson('/api/transaction', ['amount' => 1, 'coupons' => ['B'], 'tokens' => ['tok-B'], 'location_id' => $locB])->assertOk();
        $this->assertTrue(SecurityAlert::where('type', 'multi_location')->where('coupon_number', 'B')->exists());
    }
}
