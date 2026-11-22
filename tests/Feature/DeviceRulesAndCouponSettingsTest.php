<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\User;
use App\Support\CouponSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeviceRulesAndCouponSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // La app actual ya envía Device-Fingerprint e Idempotency-Key: se prueba con ambos controles activos
        config(['coupons.require_idempotency_key' => true, 'coupons.require_device_binding' => true]);
    }

    private function device(Location $location): Device
    {
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => $location->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);

        return $device;
    }

    private function user(string $role, ?Location $home = null): User
    {
        $user = User::factory()->create(['role' => $role, 'location_id' => $home?->id]);
        Sanctum::actingAs($user, $user->couponAbilities());

        return $user;
    }

    private function coupon(): void
    {
        DB::table('coupons')->insert(['coupon_number' => 'A', 'coupon_token' => 'tok-A', 'coupon_active' => 1, 'coupon_amount' => '100.00', 'coupon_balance' => '100.00', 'coupon_expire_date' => now()->addYear()->toDateString()]);
    }

    private function pay(?Device $device, string $key = 'key-12345678', array $extra = [])
    {
        $headers = ['Idempotency-Key' => $key] + ($device ? ['Device-Fingerprint' => $device->device_fingerprint] : []);

        return $this->postJson('/api/transaction', ['amount' => 10, 'coupons' => ['A'], 'tokens' => ['tok-A']] + $extra, $headers);
    }

    public function test_idempotency_key_and_device_are_required_and_the_response_has_id_and_date(): void
    {
        $home = Location::factory()->create(['is_active' => true]);
        $this->user('CASHIER', $home);
        $this->coupon();
        $device = $this->device($home);

        $this->postJson('/api/transaction', ['amount' => 10, 'coupons' => ['A'], 'tokens' => ['tok-A']], ['Device-Fingerprint' => $device->device_fingerprint])->assertStatus(400);
        $this->pay(null)->assertStatus(403); // sin dispositivo registrado

        $res = $this->pay($device)->assertOk()->assertJsonStructure(['transaction_id', 'transaction_ids', 'date', 'coupons' => [['coupon_number', 'transaction_id']]]);
        $this->assertSame($res->json('transaction_id'), $res->json('coupons.0.transaction_id'));
        $this->assertNotFalse(strtotime($res->json('date')));

        // El mismo reintento devuelve lo mismo y no cobra de nuevo
        $this->pay($device)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('transaction_id', $res->json('transaction_id'));
        $this->assertSame(1, DB::table('coupon_transactions')->count());
    }

    public function test_the_location_sent_by_the_client_is_ignored_with_a_device(): void
    {
        $home = Location::factory()->create(['is_active' => true]);
        $other = Location::factory()->create(['is_active' => true]);
        $this->user('CASHIER', $home);
        $this->coupon();

        $this->pay($this->device($home), 'key-aaaaaaaa', ['location_id' => $other->id])->assertOk();
        $this->assertDatabaseHas('coupon_transactions', ['location_id' => $home->id, 'location_source' => 'device']);
    }

    public function test_the_user_must_belong_to_the_device_location(): void
    {
        $home = Location::factory()->create(['is_active' => true]);
        $other = Location::factory()->create(['is_active' => true]);
        $cashier = $this->user('CASHIER', $other);
        $this->coupon();
        $device = $this->device($home);

        $this->pay($device)->assertStatus(403)->assertJsonPath('error', 'User is not assigned to the location of this device');
        $this->assertDatabaseHas('coupon_security_alerts', ['type' => 'user_wrong_location']);
        $this->assertSame(0, DB::table('coupon_transactions')->count());

        // Una localidad adicional de su lista también cuenta
        $cashier->locations()->attach($home->id);
        $this->pay($device, 'key-bbbbbbbb')->assertOk();

        // Un ADMIN opera en cualquier localidad
        $this->user('ADMIN', $other);
        $this->pay($device, 'key-cccccccc')->assertOk();
    }

    public function test_coupon_parameters_are_saved_from_the_system_settings_screen_and_override_the_env(): void
    {
        foreach (['system_settings.view', 'system_settings.edit'] as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('system_settings.view', 'system_settings.edit');
        $this->actingAs($admin, 'admin')->get(route('admin.system-settings.edit'))->assertOk()->assertSee('Exigir dispositivo registrado')->assertSee('Cobros máximos por cupón al día');

        $base = ['admin_password_min_length' => 12, 'pos_password_min_length' => 4, 'admin_max_failed_login_attempts' => 5, 'admin_lockout_minutes' => 15];
        $this->put(route('admin.system-settings.update'), $base + ['coupon' => ['require_device_binding' => '0', 'limits__per_coupon_daily_count' => '3', 'max_amount' => '250.5', 'alerts__business_hours' => '7-22']])
            ->assertSessionHasNoErrors();

        CouponSettings::apply();
        $this->assertFalse(config('coupons.require_device_binding'));
        $this->assertSame(3, config('coupons.limits.per_coupon_daily_count'));
        $this->assertSame('250.50', config('coupons.max_amount'));
        $this->assertSame('7-22', config('coupons.alerts.business_hours'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'update', 'entity_type' => 'coupon_settings']);

        $this->put(route('admin.system-settings.update'), $base + ['coupon' => ['alerts__business_hours' => 'de 7 a 22', 'max_amount' => '-1']])
            ->assertSessionHasErrors(['coupon.alerts__business_hours', 'coupon.max_amount']);
    }

    public function test_login_requires_a_registered_device_and_a_user_of_its_location(): void
    {
        $home = Location::factory()->create(['is_active' => true]);
        $other = Location::factory()->create(['is_active' => true]);
        User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'role' => 'CASHIER', 'location_id' => $home->id]);
        User::factory()->create(['username' => 'jefe@example.com', 'password' => 'Correct-Horse-9!', 'role' => 'ADMIN', 'location_id' => $other->id]);
        $login = fn (string $user, ?Device $device) => $this->postJson('/api/login', ['username' => $user, 'password' => 'Correct-Horse-9!'], $device ? ['Device-Fingerprint' => $device->device_fingerprint] : []);

        $login('caja@example.com', null)->assertStatus(403)->assertJsonPath('code', 'device_not_authorized');
        $login('caja@example.com', $this->device($other))->assertStatus(403)->assertJsonPath('code', 'user_wrong_location');
        $login('caja@example.com', $this->device($home))->assertOk()->assertJsonStructure(['token', 'dispensers', 'location']);
        $login('jefe@example.com', $this->device($home))->assertOk(); // un ADMIN entra en cualquier localidad

        // Una clave incorrecta no revela nada del dispositivo: sigue siendo el mismo 401
        $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'mal'])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_transaction_returns_the_sale_data_for_the_ticket(): void
    {
        $home = Location::factory()->create(['is_active' => true, 'name' => 'Zona 1']);
        $this->user('CASHIER', $home);
        $this->coupon();

        $this->pay($this->device($home))->assertOk()
            ->assertJsonPath('amount', '10.00')->assertJsonPath('location_name', 'Zona 1')->assertJsonPath('location_id', $home->id)
            ->assertJsonStructure(['transaction_id', 'date', 'created_at']);
    }
}
