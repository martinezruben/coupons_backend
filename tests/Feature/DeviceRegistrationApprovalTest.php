<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Support\DevicePairingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeviceRegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> secretos TOTP por id de administrador */
    private array $secrets = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(string $name, array $permissions = ['devices.view', 'devices.register'], bool $mfa = true): AdminUser
    {
        $a = AdminUser::factory()->create(['name' => $name]);
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
            $a->givePermissionTo($p);
        }
        if ($mfa) {
            $this->secrets[$a->id] = (new Google2FA)->generateSecretKey(32);
            $a->forceFill(['totp_secret' => Crypt::encryptString($this->secrets[$a->id]), 'totp_enabled' => true])->save();
        }

        return $a;
    }

    private function code(AdminUser $a): string
    {
        return (new Google2FA)->getCurrentOtp($this->secrets[$a->id]);
    }

    private function payload(Location $location, AdminUser $one, AdminUser $two, array $over = []): array
    {
        return array_merge([
            'device_fingerprint' => 'android-fp-panel-001', 'name' => 'Caja 1', 'location_id' => $location->id,
            'approvals' => [['admin_user_id' => $one->id, 'code' => $this->code($one)], ['admin_user_id' => $two->id, 'code' => $this->code($two)]],
        ], $over);
    }

    // ---- MFA obligatorio

    public function test_an_admin_without_mfa_can_only_reach_the_activation_screen(): void
    {
        config(['admin.mfa_required' => true]);
        $user = $this->admin('Sin MFA', ['customers.view'], mfa: false);
        $this->actingAs($user, 'admin');

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.2fa.show'));
        $this->get(route('admin.screens.index', 'customers'))->assertRedirect(route('admin.2fa.show'));
        $this->getJson(route('admin.dashboard'))->assertForbidden();
        $this->get(route('admin.2fa.show'))->assertOk()->assertSee('es obligatoria');
        $this->post(route('admin.logout'))->assertRedirect();
    }

    public function test_after_activating_mfa_the_panel_opens_and_it_cannot_be_disabled(): void
    {
        config(['admin.mfa_required' => true]);
        $user = $this->admin('Nuevo', ['customers.view'], mfa: false);
        $this->actingAs($user, 'admin');

        $this->get(route('admin.2fa.show'))->assertOk();
        $secret = session('admin_2fa_pending');
        $this->post(route('admin.2fa.enable'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertRedirect(route('admin.2fa.show'));

        $this->assertTrue((bool) $user->fresh()->totp_enabled);
        $this->get(route('admin.screens.index', 'customers'))->assertOk();

        $this->post(route('admin.2fa.disable'), ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasErrors('code');
        $this->assertTrue((bool) $user->fresh()->totp_enabled, 'no se puede desactivar mientras sea obligatorio');
    }

    public function test_the_rule_can_be_turned_off(): void
    {
        config(['admin.mfa_required' => false]);
        $this->actingAs($this->admin('Sin MFA', ['customers.view'], mfa: false), 'admin')->get(route('admin.screens.index', 'customers'))->assertOk();
    }

    // ---- Registro con dos aprobaciones

    public function test_a_device_is_registered_with_two_different_approvers(): void
    {
        $location = Location::factory()->create(['name' => 'Sede Centro', 'is_active' => true]);
        $actor = $this->admin('Ana Registradora');
        $second = $this->admin('Beto Aprobador');
        $this->actingAs($actor, 'admin');

        $this->get(route('admin.devices.register.form'))->assertOk()->assertSee('Sede Centro')->assertSee('Beto Aprobador');
        $this->post(route('admin.devices.register'), $this->payload($location, $actor, $second))->assertSessionHasNoErrors()->assertRedirect(route('admin.screens.index', 'devices'));

        $device = Device::where('device_fingerprint', 'android-fp-panel-001')->first();
        $this->assertNotNull($device);
        $this->assertSame($location->id, $device->location_id);
        $this->assertTrue($device->is_enabled);
        $this->assertSame('Caja 1', $device->name);
        $this->assertNull($device->registered_at, 'se completa al vincular el terminal');
        $this->assertSame(1, License::where('device_id', $device->id)->where('status', 'ACTIVE')->count());

        $log = AdminAuditLog::where('action', 'registered')->first();
        $this->assertSame('Ana Registradora', $log->changes['registrado_por'][1]);
        $this->assertSame('Ana Registradora y Beto Aprobador', $log->changes['aprobado_por'][1]);
        $this->assertStringContainsString('aprobado por Ana Registradora y Beto Aprobador', $log->summary);
    }

    public function test_one_person_cannot_approve_twice_and_wrong_codes_are_rejected_without_burning_the_good_one(): void
    {
        $location = Location::factory()->create(['is_active' => true]);
        $actor = $this->admin('Ana');
        $second = $this->admin('Beto');
        $this->actingAs($actor, 'admin');

        // La misma persona dos veces
        $same = $this->payload($location, $actor, $actor);
        $this->post(route('admin.devices.register'), $same)->assertSessionHasErrors('approvals.1.admin_user_id');

        // Un código incorrecto: no se registra y el código bueno de la otra persona sigue sirviendo
        $bad = $this->payload($location, $actor, $second, ['approvals' => [['admin_user_id' => $actor->id, 'code' => $this->code($actor)], ['admin_user_id' => $second->id, 'code' => '000000']]]);
        $this->post(route('admin.devices.register'), $bad)->assertSessionHasErrors('approvals.1.code');
        $this->assertSame(0, Device::count());

        $this->post(route('admin.devices.register'), $this->payload($location, $actor, $second))->assertSessionHasNoErrors();
        $this->assertSame(1, Device::count());
    }

    public function test_a_code_cannot_be_reused_and_attempts_are_limited(): void
    {
        $location = Location::factory()->create(['is_active' => true]);
        $actor = $this->admin('Ana');
        $second = $this->admin('Beto');
        $this->actingAs($actor, 'admin');
        $first = $this->payload($location, $actor, $second);
        $this->post(route('admin.devices.register'), $first)->assertSessionHasNoErrors();

        // Mismos códigos para otro dispositivo: ya fueron usados
        $replay = array_merge($first, ['device_fingerprint' => 'android-fp-otro']);
        $this->post(route('admin.devices.register'), $replay)->assertSessionHasErrors(['approvals.0.code', 'approvals.1.code']);
        $this->assertSame(1, Device::count());

        // Demasiados intentos fallidos bloquean a la persona
        foreach (range(1, 5) as $i) {
            $this->post(route('admin.devices.register'), $this->payload($location, $actor, $second, ['device_fingerprint' => "fp-x-$i", 'approvals' => [['admin_user_id' => $actor->id, 'code' => '111111'], ['admin_user_id' => $second->id, 'code' => '222222']]]));
        }
        $this->post(route('admin.devices.register'), $this->payload($location, $actor, $second, ['device_fingerprint' => 'fp-final']))->assertSessionHasErrors();
        $this->assertSame(1, Device::count());
    }

    public function test_only_admins_with_register_permission_and_mfa_can_approve(): void
    {
        $location = Location::factory()->create(['is_active' => true]);
        $actor = $this->admin('Ana');
        $noPermission = $this->admin('Sin permiso', ['devices.view']);
        $this->actingAs($actor, 'admin');

        $this->post(route('admin.devices.register'), $this->payload($location, $actor, $noPermission))->assertSessionHasErrors('approvals.1.admin_user_id');
        $this->get(route('admin.devices.register.form'))->assertDontSee('Sin permiso');

        // Quien no tiene el permiso no abre ni envía el formulario
        $this->actingAs($noPermission, 'admin');
        $this->get(route('admin.devices.register.form'))->assertForbidden();
        $this->post(route('admin.devices.register'), $this->payload($location, $actor, $actor))->assertForbidden();
        $this->assertSame(0, Device::count());
    }

    public function test_validation_of_location_and_fingerprint(): void
    {
        $inactive = Location::factory()->create(['is_active' => false]);
        $actor = $this->admin('Ana');
        $second = $this->admin('Beto');
        Device::factory()->create(['device_fingerprint' => 'ya-existe']);
        $this->actingAs($actor, 'admin');

        $this->post(route('admin.devices.register'), $this->payload($inactive, $actor, $second))->assertSessionHasErrors('location_id');
        $ok = Location::factory()->create(['is_active' => true]);
        $this->post(route('admin.devices.register'), $this->payload($ok, $actor, $second, ['device_fingerprint' => 'ya-existe']))->assertSessionHasErrors('device_fingerprint');
        $this->post(route('admin.devices.register'), $this->payload($ok, $actor, $second, ['approvals' => [['admin_user_id' => $actor->id, 'code' => $this->code($actor)]]]))->assertSessionHasErrors('approvals');
    }

    public function test_the_devices_screen_offers_the_register_button_only_with_permission(): void
    {
        $with = $this->admin('Con');
        $this->actingAs($with, 'admin')->get(route('admin.screens.index', 'devices'))->assertOk()->assertSee('Registrar dispositivo');
        $without = $this->admin('Sin', ['devices.view']);
        $this->actingAs($without, 'admin')->get(route('admin.screens.index', 'devices'))->assertOk()->assertDontSee('Registrar dispositivo');
    }

    // ---- API: un dispositivo desconocido ya no se registra solo

    public function test_an_unknown_device_cannot_self_register_but_a_panel_registered_one_can_link(): void
    {
        $location = Location::factory()->create(['is_active' => true]);
        $other = Location::factory()->create(['is_active' => true]);
        $code = DevicePairingToken::generateNew($location->id)['code'];

        $this->postJson('/api/auth/register-device', ['device_fingerprint' => 'desconocido', 'pairing_token' => $code])
            ->assertForbidden()->assertJsonPath('code', 'DEVICE_NOT_REGISTERED');
        $this->assertSame(0, Device::count());

        // Registrado desde el panel: con el código de su localidad se vincula
        $device = Device::factory()->create(['device_fingerprint' => 'registrado', 'location_id' => $location->id, 'is_enabled' => true]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);

        $wrong = DevicePairingToken::generateNew($other->id)['code'];
        $this->postJson('/api/auth/register-device', ['device_fingerprint' => 'registrado', 'pairing_token' => $wrong])->assertForbidden();

        $this->postJson('/api/auth/register-device', ['device_fingerprint' => 'registrado', 'pairing_token' => $code])
            ->assertOk()->assertJsonPath('location_id', $location->id)->assertJsonStructure(['token', 'license_key']);
    }
}
