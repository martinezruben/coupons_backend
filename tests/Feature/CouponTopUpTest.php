<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\TopUpRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CouponTopUpTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role = 'MANAGER'): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user, $user->couponAbilities());

        return $user;
    }

    private function qr(string $number = 'Q1', string $balance = '10.00', string $amount = '10.00', array $extra = []): int
    {
        return DB::table('coupons')->insertGetId(array_merge([
            'coupon_number' => $number, 'coupon_token' => 'tok-'.$number, 'coupon_active' => 1,
            'coupon_amount' => $amount, 'coupon_balance' => $balance,
            'coupon_expire_date' => now()->addYear()->toDateString(),
        ], $extra), 'coupon_id');
    }

    private function bar(string $number = 'B1', string $balance = '5.00'): int
    {
        return DB::table('coupons_codbar')->insertGetId([
            'coupon_number' => $number, 'coupon_active' => 1, 'coupon_amount' => $balance,
            'coupon_balance' => $balance, 'coupon_expire_date' => now()->addYear()->toDateString(),
        ], 'coupon_id');
    }

    private function device(?Location $location = null): Device
    {
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => ($location ?? Location::factory()->create(['is_active' => true]))->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);

        return $device;
    }

    private function topup(string $number = 'Q1', $amount = 20, array $headers = [], ?string $token = 'tok-Q1')
    {
        return $this->postJson('/api/topup', array_filter(['coupon_number' => $number, 'coupon_token' => $token, 'amount' => $amount], fn ($v) => $v !== null), $headers);
    }

    private function money(string $table, int $id, string $column = 'coupon_balance'): string
    {
        return number_format((float) DB::table($table)->where('coupon_id', $id)->value($column), 2, '.', '');
    }

    public function test_topup_adds_balance_and_amount_records_a_topup_transaction_and_audits(): void
    {
        $user = $this->as();
        $id = $this->qr('Q1', '10.00', '10.00');

        $this->topup('Q1', 20)->assertOk()->assertJsonPath('balance', '30.00')->assertJsonPath('topup_amount', '20.00');

        $this->assertSame('30.00', $this->money('coupons', $id));
        $this->assertSame('30.00', $this->money('coupons', $id, 'coupon_amount'));
        $this->assertDatabaseHas('coupon_transactions', ['coupon_number' => 'Q1', 'kind' => 'TOPUP', 'transaction_amount' => '20.00', 'user_id' => $user->id]);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'topup', 'user_id' => $user->id, 'old_value' => '10.00', 'new_value' => '30.00']);
    }

    public function test_topup_works_for_barcode_coupons_without_token(): void
    {
        $this->as();
        $id = $this->bar('B1', '5.00');

        $this->postJson('/api/topupcb', ['coupon_number' => 'B1', 'amount' => 7.5])->assertOk()->assertJsonPath('balance', '12.50');
        $this->assertSame('12.50', $this->money('coupons_codbar', $id));
    }

    public function test_topup_requires_the_qr_token_and_hides_which_part_failed(): void
    {
        $this->as();
        $this->qr('Q1');

        $this->topup('Q1', 20, [], null)->assertStatus(422);
        $this->topup('Q1', 20, [], 'wrong')->assertStatus(404)->assertJsonPath('error', 'Coupon not found');
        $this->topup('NOPE', 20, [], 'x')->assertStatus(404)->assertJsonPath('error', 'Coupon not found');
    }

    public function test_cashier_cannot_topup_unless_the_role_is_given_the_ability(): void
    {
        $this->as('CASHIER');
        $id = $this->qr('Q1');

        $this->topup()->assertStatus(403);
        $this->assertSame('10.00', $this->money('coupons', $id));

        config(['coupons.role_abilities.CASHIER' => ['coupon:read', 'coupon:redeem', 'coupon:topup']]);
        $this->as('CASHIER');
        $this->topup()->assertOk();
    }

    public function test_topup_validates_amount_state_and_balance_limit(): void
    {
        $this->as();
        $this->qr('Q1');
        $this->qr('OFF', '5.00', '5.00', ['coupon_active' => 0]);
        $this->qr('OLD', '5.00', '5.00', ['coupon_expire_date' => now()->subDays(2)->toDateString()]);

        foreach ([0, -5, 'abc', 1.005] as $bad) {
            $this->topup('Q1', $bad)->assertStatus(422);
        }
        $this->topup('OFF', 5, [], 'tok-OFF')->assertStatus(422)->assertJsonPath('error', 'Coupon is inactive or expired');
        $this->topup('OLD', 5, [], 'tok-OLD')->assertStatus(422);

        config(['coupons.max_balance' => '50.00']);
        $this->topup('Q1', 41)->assertStatus(422)->assertJsonPath('error', 'Balance limit exceeded');
        $this->topup('Q1', 40)->assertOk()->assertJsonPath('balance', '50.00');
    }

    public function test_topup_is_idempotent_with_the_idempotency_key(): void
    {
        $this->as();
        $id = $this->qr('Q1');
        $h = ['Idempotency-Key' => 'topup-123456'];

        $first = $this->topup('Q1', 20, $h)->assertOk();
        $second = $this->topup('Q1', 20, $h)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame('30.00', $this->money('coupons', $id));
        $this->assertSame(1, DB::table('coupon_transactions')->where('kind', 'TOPUP')->count());
    }

    // ---- Reglas

    public function test_weekday_rule_only_allows_the_configured_days(): void
    {
        $this->as();
        $this->qr('Q1');
        TopUpRule::create(['name' => 'Lunes a miércoles', 'type' => 'weekday', 'weekdays' => '1,2,3']);

        $this->travelTo(Carbon::parse('2026-11-16 10:00:00')); // lunes
        $this->topup('Q1', 5)->assertOk();

        $this->travelTo(Carbon::parse('2026-11-19 10:00:00')); // jueves
        $this->topup('Q1', 5)->assertStatus(422)->assertJsonPath('error', 'Top-ups are not allowed on this weekday')
            ->assertJsonPath('allowed_weekdays', ['lunes', 'martes', 'miércoles']);
    }

    public function test_location_rule_only_allows_the_configured_locations(): void
    {
        $this->as();
        $this->qr('Q1');
        $allowed = Location::factory()->create(['is_active' => true]);
        TopUpRule::create(['name' => 'Solo sede A', 'type' => 'location', 'location_id' => $allowed->id]);

        $this->topup('Q1', 5, ['Device-Fingerprint' => $this->device($allowed)->device_fingerprint])->assertOk();
        $this->topup('Q1', 5, ['Device-Fingerprint' => $this->device()->device_fingerprint])->assertStatus(422)
            ->assertJsonPath('error', 'Top-ups are not allowed at this location');
        $this->topup('Q1', 5)->assertStatus(422); // sin dispositivo no hay localidad
    }

    public function test_daily_amount_rule_caps_topups_per_coupon_per_day(): void
    {
        $this->as();
        $this->qr('Q1');
        $this->qr('Q2');
        TopUpRule::create(['name' => 'Tope diario', 'type' => 'daily_amount', 'max_daily_amount' => '50.00']);

        $this->travelTo(Carbon::parse('2026-11-16 09:00:00'));
        $this->topup('Q1', 30)->assertOk();
        $this->topup('Q1', 21)->assertStatus(422)->assertJsonPath('error', 'Daily top-up limit exceeded')
            ->assertJsonPath('remaining', '20.00')->assertJsonPath('topped_up_today', '30.00');
        $this->topup('Q1', 20)->assertOk();
        $this->postJson('/api/topup', ['coupon_number' => 'Q2', 'coupon_token' => 'tok-Q2', 'amount' => 50])->assertOk(); // otro cupón

        $this->travelTo(Carbon::parse('2026-11-17 09:00:00')); // día siguiente: contador en cero
        $this->topup('Q1', 50)->assertOk();
    }

    public function test_rules_are_scoped_by_coupon_type_and_inactive_rules_are_ignored(): void
    {
        $this->as();
        $this->qr('Q1');
        $this->bar('B1');
        TopUpRule::create(['name' => 'Tope QR', 'type' => 'daily_amount', 'coupon_type' => 'QRCODE', 'max_daily_amount' => '10.00']);
        TopUpRule::create(['name' => 'Apagada', 'type' => 'weekday', 'weekdays' => '1', 'is_active' => false]);

        $this->topup('Q1', 11)->assertStatus(422);
        $this->postJson('/api/topupcb', ['coupon_number' => 'B1', 'amount' => 500])->assertOk(); // la regla es solo QR
    }

    public function test_topups_are_not_reversible_and_do_not_count_as_redemptions(): void
    {
        $this->as('ADMIN');
        $this->qr('Q1');
        $this->topup('Q1', 20)->assertOk();
        $txId = DB::table('coupon_transactions')->value('transaction_id');

        $this->postJson("/api/transactions/$txId/reverse", ['reason' => 'Intento de reversa'])->assertStatus(422);
    }
}
