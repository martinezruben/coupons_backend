<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Models\User;
use App\Support\AppUnlockKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppUnlockKeyAndReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = ['admin_password_min_length' => 12, 'pos_password_min_length' => 4, 'admin_max_failed_login_attempts' => 5, 'admin_lockout_minutes' => 15];

    private function params(): SystemParameter
    {
        return SystemParameter::query()->first() ?? SystemParameter::factory()->create();
    }

    private function superAdmin(): AdminUser
    {
        $a = AdminUser::factory()->create();
        $a->assignRole(Role::findOrCreate('super-admin', 'admin'));

        return $a;
    }

    public function test_the_hash_is_interoperable_with_the_android_verifier(): void
    {
        // Vectores PBKDF2-HMAC-SHA256 y hash «clave-admin» tomados de UnlockVerifierTest de la app
        $this->assertSame('120fb6cffcf8b32c43e7225256c4f837a86548c92ccc35480805987cb70be17b', hash_pbkdf2('sha256', 'password', 'salt', 1, 64, false));
        $this->assertSame('c5e478d59288c841aa530db6845c4c8d962893a001ce4e11a4963873aa98134a', hash_pbkdf2('sha256', 'password', 'salt', 4096, 64, false));
        $fromApp = 'pbkdf2_sha256$1000$00112233445566778899aabbccddeeff$048814370334d923a4d67ad1565a01c0356e5735f543ab3550a4cb99dc96aa2c';
        $this->assertTrue(AppUnlockKey::verify('clave-admin', $fromApp));
        $this->assertFalse(AppUnlockKey::verify('clave-admiN', $fromApp));

        $mine = AppUnlockKey::make('Clave Segura 2026!');
        $this->assertMatchesRegularExpression('/^pbkdf2_sha256\$100000\$[0-9a-f]{32}\$[0-9a-f]{64}$/', $mine);
        $this->assertTrue(AppUnlockKey::verify('Clave Segura 2026!', $mine));
        $this->assertFalse(AppUnlockKey::verify('otra', $mine));
        $this->assertNotSame($mine, AppUnlockKey::make('Clave Segura 2026!')); // sal distinta cada vez
        foreach ([null, '', 'plaintext', 'md5$1$00$00'] as $bad) {
            $this->assertFalse(AppUnlockKey::verify('x', $bad));
        }
    }

    public function test_the_super_admin_sets_one_key_that_is_stored_only_as_a_hash_and_audited(): void
    {
        $this->params();
        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.system-settings.update'), self::BASE + ['app_unlock_key' => 'corta', 'app_unlock_key_confirmation' => 'corta'])->assertSessionHasErrors('app_unlock_key');
        $this->put(route('admin.system-settings.update'), self::BASE + ['app_unlock_key' => 'una-clave-larga-1', 'app_unlock_key_confirmation' => 'distinta'])->assertSessionHasErrors('app_unlock_key');

        $this->put(route('admin.system-settings.update'), self::BASE + ['app_unlock_key' => 'una-clave-larga-1', 'app_unlock_key_confirmation' => 'una-clave-larga-1', 'receipt_company_name' => 'Gasolinera Norte', 'receipt_footer' => '¡Gracias!'])->assertSessionHasNoErrors();

        $p = $this->params()->fresh();
        $this->assertTrue(AppUnlockKey::verify('una-clave-larga-1', $p->app_unlock_hash));
        $this->assertStringNotContainsString('una-clave-larga-1', $p->getRawOriginal('app_unlock_hash'));
        $this->assertNotNull($p->app_unlock_set_at);
        $this->assertSame('Gasolinera Norte', $p->receipt_company_name);
        $this->assertArrayNotHasKey('app_unlock_hash', $p->toArray());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'update', 'entity_type' => 'app_unlock_key']);
        $this->assertDatabaseMissing('admin_audit_logs', ['changes' => 'una-clave-larga-1']);

        // Dejarla vacía no la cambia
        $before = $p->app_unlock_hash;
        $this->put(route('admin.system-settings.update'), self::BASE + ['app_unlock_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame($before, $this->params()->fresh()->app_unlock_hash);
    }

    public function test_only_a_super_admin_can_change_the_key(): void
    {
        $this->params();
        foreach (['system_settings.view', 'system_settings.edit'] as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('system_settings.view', 'system_settings.edit');

        $this->actingAs($admin, 'admin')->get(route('admin.system-settings.edit'))->assertOk()->assertSee('Solo un super-administrador puede cambiarla');
        $this->put(route('admin.system-settings.update'), self::BASE + ['app_unlock_key' => 'una-clave-larga-1', 'app_unlock_key_confirmation' => 'una-clave-larga-1'])->assertForbidden();
        $this->assertNull($this->params()->fresh()->app_unlock_hash);
    }

    public function test_login_and_profile_deliver_the_hash_and_the_receipt_data_to_the_app(): void
    {
        $p = $this->params();
        $p->update(['receipt_company_name' => 'Gasolinera Norte', 'receipt_footer' => '¡Gracias!']);
        $p->forceFill(['app_unlock_hash' => AppUnlockKey::make('una-clave-larga-1')])->save();
        $loc = Location::factory()->create(['is_active' => true, 'address' => 'Av. Principal 10', 'phone' => '809-555-0100']);
        $user = User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'location_id' => $loc->id]);

        $res = $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'])->assertOk()
            ->assertJsonPath('receipt.company_name', 'Gasolinera Norte')->assertJsonPath('receipt.footer', '¡Gracias!')
            ->assertJsonPath('receipt.phone', '809-555-0100')->assertJsonPath('receipt.address', 'Av. Principal 10');
        $this->assertTrue(AppUnlockKey::verify('una-clave-larga-1', $res->json('app_settings.url_unlock')));

        Sanctum::actingAs($user, $user->couponAbilities());
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('receipt.phone', '809-555-0100')
            ->assertJsonPath('app_settings.url_unlock', fn ($h) => str_starts_with((string) $h, 'pbkdf2_sha256$'));

    }

    public function test_without_a_key_the_app_gets_null_and_the_company_falls_back_to_the_app_name(): void
    {
        $this->params()->update(['receipt_company_name' => null]);
        User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!']);

        $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'])->assertOk()
            ->assertJsonPath('app_settings.url_unlock', null)->assertJsonPath('receipt.company_name', config('app.name'));
    }

    public function test_the_location_phone_is_edited_in_the_locations_form(): void
    {
        $admin = AdminUser::factory()->create();
        foreach (['locations.view', 'locations.edit'] as $perm) {
            Permission::findOrCreate($perm, 'admin');
        }
        $admin->givePermissionTo('locations.view', 'locations.edit');
        $this->actingAs($admin, 'admin')->post(route('admin.screens.store', 'locations'), ['name' => 'Sede Sur', 'phone' => '809-555-0199', 'dispensers' => 4, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('809-555-0199', Location::where('name', 'Sede Sur')->value('phone'));
    }
}
