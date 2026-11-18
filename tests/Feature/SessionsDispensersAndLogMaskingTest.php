<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\User;
use App\Support\SensitiveData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SessionsDispensersAndLogMaskingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    public function test_dispensers_must_be_between_1_and_20_and_are_listed(): void
    {
        $this->actingAs($this->admin('locations.view', 'locations.edit'), 'admin');

        foreach ([0, 21, 'x', ''] as $bad) {
            $this->post(route('admin.screens.store', 'locations'), ['name' => 'Sede Norte', 'dispensers' => $bad, 'is_active' => 1])->assertSessionHasErrors('dispensers');
        }
        $this->post(route('admin.screens.store', 'locations'), ['name' => 'Sede Norte', 'dispensers' => 12, 'is_active' => 1])->assertSessionHasNoErrors();

        $loc = Location::where('name', 'Sede Norte')->firstOrFail();
        $this->assertSame(12, $loc->dispensers);
        $this->get(route('admin.screens.edit', ['locations', $loc->id]))->assertOk()->assertSee('Número de dispensadores');
    }

    public function test_the_app_receives_the_dispensers_of_the_location_by_api(): void
    {
        $loc = Location::factory()->create(['is_active' => true, 'dispensers' => 7]);
        $user = User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'location_id' => $loc->id]);

        $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'])
            ->assertOk()->assertJsonPath('dispensers', 7)->assertJsonPath('location.location_id', $loc->id)->assertJsonPath('location.dispensers', 7);

        // Con dispositivo registrado manda la localidad del dispositivo
        $other = Location::factory()->create(['is_active' => true, 'dispensers' => 3]);
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => $other->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);
        Sanctum::actingAs($user, $user->couponAbilities());
        $this->getJson('/api/profile', ['Device-Fingerprint' => $device->device_fingerprint])->assertOk()->assertJsonPath('dispensers', 3)->assertJsonPath('location.location_name', $other->name);

        // La API de dispositivos también la entrega
        $device->refresh();
        Sanctum::actingAs($device, []);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('dispensers', 3);
    }

    public function test_the_account_is_locked_after_failed_logins_and_unlocked_by_an_admin(): void
    {
        config(['coupons.login_max_attempts' => 3, 'coupons.login_lockout_minutes' => 15]);
        $user = User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!']);
        $login = fn (string $pw) => $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => $pw]);

        $login('mal')->assertStatus(401);
        $login('mal')->assertStatus(401);
        $login('mal')->assertStatus(401); // se bloquea
        $this->assertNotNull($user->fresh()->locked_until);
        $this->assertDatabaseHas('coupon_security_alerts', ['type' => 'user_locked']);

        // Ni con la clave correcta entra, y la respuesta no delata el bloqueo
        $login('Correct-Horse-9!')->assertStatus(401)->assertExactJson(['error' => 'Unauthorized']);

        $this->actingAs($this->admin('android_users.view', 'android_users.edit'), 'admin')
            ->post(route('admin.android-users.unlock', $user))->assertSessionHas('status');
        $login('Correct-Horse-9!')->assertOk();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_a_success_resets_the_failure_counter(): void
    {
        config(['coupons.login_max_attempts' => 3]);
        $user = User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!']);
        $login = fn (string $pw) => $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => $pw]);

        $login('mal');
        $login('mal');
        $login('Correct-Horse-9!')->assertOk();
        $login('mal');
        $login('mal');
        $this->assertNull($user->fresh()->locked_until);
    }

    public function test_password_change_deactivation_and_the_button_close_app_sessions(): void
    {
        $user = User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'role' => 'CASHIER']);
        $this->actingAs($this->admin('android_users.view', 'android_users.edit'), 'admin');

        $user->createToken('tablet-1');
        $user->createToken('tablet-2');
        $this->post(route('admin.android-users.revoke-sessions', $user))->assertSessionHas('status');
        $this->assertSame(0, $user->tokens()->count());

        $user->createToken('tablet-1');
        $this->put(route('admin.screens.update', ['android-users', $user->getKey()]), ['username' => $user->username, 'role' => 'CASHIER', 'is_active' => 1, 'password' => 'Nueva-Clave-77!'])->assertSessionHasNoErrors();
        $this->assertSame(0, $user->tokens()->count());

        $user->createToken('tablet-1');
        $this->put(route('admin.screens.update', ['android-users', $user->getKey()]), ['username' => $user->username, 'role' => 'CASHIER', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_coupon_numbers_are_masked_in_logs(): void
    {
        $masked = SensitiveData::mask(['coupon_number' => '123456789012', 'coupons' => ['111122223333', '444455556666'], 'amount' => 5, 'password' => 'x']);
        $this->assertSame('********9012', $masked['coupon_number']);
        $this->assertSame(['********3333', '********6666'], $masked['coupons']);
        $this->assertSame(5, $masked['amount']);
        $this->assertSame('***', $masked['password']);

        $json = SensitiveData::maskJson('{"coupons":[{"coupon_number":"123456789012","balance":"5.00"}]}', 500);
        $this->assertStringContainsString('********9012', $json);
        $this->assertStringNotContainsString('123456789012', $json);
    }
}
