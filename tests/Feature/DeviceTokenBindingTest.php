<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeviceTokenBindingTest extends TestCase
{
    use RefreshDatabase;

    private Location $loc;

    protected function setUp(): void
    {
        parent::setUp();
        config(['coupons.require_device_binding' => true, 'coupons.require_device_token' => true, 'coupons.require_idempotency_key' => false]);
        $this->loc = Location::factory()->create(['is_active' => true]);
        User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'role' => 'CASHIER', 'location_id' => $this->loc->id]);
        DB::table('coupons')->insert(['coupon_number' => 'A', 'coupon_token' => 'tok-A', 'coupon_active' => 1, 'coupon_amount' => '100.00', 'coupon_balance' => '100.00', 'coupon_expire_date' => now()->addYear()->toDateString()]);
    }

    /** @return array{0: Device, 1: array<string, string>} terminal con licencia y las cabeceras que enviaría su app */
    private function terminal(): array
    {
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => $this->loc->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);

        return [$device, ['Device-Fingerprint' => $device->device_fingerprint, 'Device-Token' => $device->createToken('pos-device')->plainTextToken]];
    }

    private function login(array $headers)
    {
        return $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'], $headers);
    }

    private function pay(string $userToken, array $headers)
    {
        return $this->withToken($userToken)->postJson('/api/transaction', ['amount' => 5, 'coupons' => ['A'], 'tokens' => ['tok-A']], $headers);
    }

    public function test_login_needs_the_private_device_token_not_only_the_copyable_fingerprint(): void
    {
        [$device, $h] = $this->terminal();

        $this->login(['Device-Fingerprint' => $device->device_fingerprint])->assertStatus(403)->assertJsonPath('code', 'device_token_required');
        $this->login($h)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_stolen_fingerprint_with_another_token_is_rejected_and_alerts(): void
    {
        [$a] = $this->terminal();
        [, $hb] = $this->terminal();

        $this->login(['Device-Fingerprint' => $a->device_fingerprint, 'Device-Token' => $hb['Device-Token']])
            ->assertStatus(403)->assertJsonPath('code', 'device_token_invalid');
        $this->assertDatabaseHas('coupon_security_alerts', ['type' => 'device_token_invalid', 'severity' => 'high']);

        // Un token inventado, o el token de una persona, tampoco valen
        $this->login(['Device-Fingerprint' => $a->device_fingerprint, 'Device-Token' => '1|inventado'])->assertStatus(403)->assertJsonPath('code', 'device_token_invalid');
        $userToken = User::first()->createToken('x')->plainTextToken;
        $this->login(['Device-Fingerprint' => $a->device_fingerprint, 'Device-Token' => $userToken])->assertStatus(403)->assertJsonPath('code', 'device_token_invalid');
    }

    public function test_the_user_session_is_tied_to_the_terminal_where_it_started(): void
    {
        [, $ha] = $this->terminal();
        [, $hb] = $this->terminal();
        $token = $this->login($ha)->json('token');

        $this->pay($token, $ha)->assertOk();
        $this->pay($token, $hb)->assertStatus(403)->assertJsonPath('code', 'session_device_mismatch');
        $this->pay($token, ['Device-Fingerprint' => $ha['Device-Fingerprint']])->assertStatus(403)->assertJsonPath('code', 'device_token_required');
    }

    public function test_every_coupon_route_checks_the_terminal_including_balance(): void
    {
        [, $h] = $this->terminal();
        $token = $this->login($h)->json('token');

        $this->withToken($token)->getJson('/api/balance?coupon_number=A&coupon_token=tok-A', $h)->assertOk();
        $this->withToken($token)->getJson('/api/balance?coupon_number=A&coupon_token=tok-A')->assertStatus(403)->assertJsonPath('code', 'device_token_required');
    }

    public function test_a_revoked_device_token_stops_working_after_repairing_or_blocking(): void
    {
        [$device, $h] = $this->terminal();
        $token = $this->login($h)->json('token');
        $this->pay($token, $h)->assertOk();

        $device->tokens()->delete(); // re-emparejado o bloqueo del terminal
        $this->pay($token, $h)->assertStatus(403)->assertJsonPath('code', 'device_token_invalid');

        // Con un token nuevo del mismo terminal, la sesión sigue siendo válida (está atada al terminal, no al token)
        $h['Device-Token'] = $device->createToken('pos-device')->plainTextToken;
        $this->pay($token, $h)->assertOk();
    }

    public function test_transition_mode_accepts_the_fingerprint_alone_but_never_a_wrong_token(): void
    {
        config(['coupons.require_device_token' => false]);
        [$device] = $this->terminal();
        [, $hb] = $this->terminal();

        $token = $this->login(['Device-Fingerprint' => $device->device_fingerprint])->assertOk()->json('token');
        $this->pay($token, ['Device-Fingerprint' => $device->device_fingerprint])->assertOk();
        $this->pay($token, ['Device-Fingerprint' => $device->device_fingerprint, 'Device-Token' => $hb['Device-Token']])
            ->assertStatus(403)->assertJsonPath('code', 'device_token_invalid');
    }
}
