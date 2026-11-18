<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiErrorCodesTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role = 'CASHIER'): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user, $user->couponAbilities());

        return $user;
    }

    private function coupon(string $balance = '5.00'): void
    {
        DB::table('coupons')->insert(['coupon_number' => 'A', 'coupon_token' => 'tok-A', 'coupon_active' => 1, 'coupon_amount' => '100.00', 'coupon_balance' => $balance, 'coupon_expire_date' => now()->addYear()->toDateString()]);
    }

    public function test_business_errors_carry_a_stable_code(): void
    {
        $this->as();
        $this->coupon();

        $this->postJson('/api/transaction', ['amount' => 50, 'coupons' => ['A'], 'tokens' => ['tok-A']])
            ->assertStatus(400)->assertJsonPath('code', 'insufficient_balance')->assertJsonPath('error', 'Insufficient balance');
        $this->postJson('/api/transaction', ['amount' => 1, 'coupons' => ['NOPE'], 'tokens' => ['x']])
            ->assertStatus(404)->assertJsonPath('code', 'coupon_not_found');

        DB::table('coupons')->update(['coupon_active' => 0]);
        $this->postJson('/api/transaction', ['amount' => 1, 'coupons' => ['A'], 'tokens' => ['tok-A']])
            ->assertStatus(422)->assertJsonPath('code', 'coupon_inactive_or_expired');
    }

    public function test_validation_errors_use_the_same_shape_with_an_error_text(): void
    {
        $this->as();

        $this->postJson('/api/transaction', ['amount' => 1.234, 'coupons' => ['A']])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['error', 'message', 'errors' => ['amount']]);
        $this->assertNotSame('', $this->postJson('/api/transaction', [])->json('error'));
    }

    public function test_forbidden_unauthenticated_and_not_found_have_codes(): void
    {
        $this->getJson('/api/profile')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');

        $this->as('CASHIER');
        $this->postJson('/api/restore', ['coupon_number' => 'A', 'coupon_amount' => 1, 'reason' => 'Prueba de ajuste'])
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/no-such-route')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_rate_limit_responses_have_error_and_code(): void
    {
        config(['coupons.login_max_attempts' => 0]);
        User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!']);

        $last = null;
        for ($i = 0; $i < 7; $i++) {
            $last = $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'mal']);
        }
        $last->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertJsonPath('error', 'Too many requests')->assertJsonStructure(['retry_after']);
    }

    public function test_idempotency_and_login_errors_have_codes(): void
    {
        config(['coupons.require_idempotency_key' => true]);
        $this->as();
        $this->postJson('/api/transaction', ['amount' => 1, 'coupons' => ['A']])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    }
}
