<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SystemParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminConsoleCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_create_makes_a_super_admin_who_can_log_in(): void
    {
        $this->artisan('admin:create', ['email' => 'Root@Coupify.test', '--name' => 'Root', '--password' => 'Clave-Inicial-123!'])
            ->expectsOutputToContain('Administrador creado: root@coupify.test')
            ->assertSuccessful();

        $admin = AdminUser::where('email', 'root@coupify.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame('Root', $admin->name);
        $this->assertTrue(Hash::check('Clave-Inicial-123!', $admin->password));

        $this->post(route('admin.login.store'), ['email' => 'root@coupify.test', 'password' => 'Clave-Inicial-123!'])->assertRedirect();
        $this->assertAuthenticated('admin');
    }

    public function test_admin_create_rejects_duplicates_weak_passwords_and_unknown_roles(): void
    {
        AdminUser::factory()->create(['email' => 'dup@coupify.test']);

        $this->artisan('admin:create', ['email' => 'dup@coupify.test', '--password' => 'Clave-Inicial-123!'])
            ->expectsOutputToContain('Ya existe un administrador con ese correo.')->assertFailed();
        $this->artisan('admin:create', ['email' => 'nuevo@coupify.test', '--password' => '123'])
            ->assertFailed();
        $this->artisan('admin:create', ['email' => 'nuevo@coupify.test', '--password' => 'Clave-Inicial-123!', '--role' => 'no-existe'])
            ->expectsOutputToContain('no existe')->assertFailed();
        $this->artisan('admin:create', ['email' => 'no-es-correo', '--password' => 'Clave-Inicial-123!'])->assertFailed();

        $this->assertSame(0, AdminUser::where('email', 'nuevo@coupify.test')->count());
    }

    public function test_admin_create_respects_the_complexity_policy(): void
    {
        SystemParameter::query()->update(['admin_password_require_symbol' => true]);

        $this->artisan('admin:create', ['email' => 'sin@coupify.test', '--password' => 'SinSimbolosAqui1'])->assertFailed();
        $this->assertSame(0, AdminUser::where('email', 'sin@coupify.test')->count());
    }

    public function test_admin_create_can_assign_an_existing_role(): void
    {
        Role::findOrCreate('gerente', 'admin');

        $this->artisan('admin:create', ['email' => 'ger@coupify.test', '--password' => 'Clave-Inicial-123!', '--role' => 'gerente'])->assertSuccessful();

        $admin = AdminUser::where('email', 'ger@coupify.test')->first();
        $this->assertSame(['gerente'], $admin->getRoleNames()->all());
        $this->assertFalse($admin->isSuperAdmin());
    }

    public function test_admin_create_without_a_password_and_without_a_terminal_fails(): void
    {
        $this->artisan('admin:create', ['email' => 'x@coupify.test', '--no-interaction' => true])
            ->expectsOutputToContain('Sin terminal interactiva')->assertFailed();
    }

    public function test_admin_reset_password_changes_it_and_closes_sessions(): void
    {
        $admin = AdminUser::factory()->create(['email' => 'r@coupify.test', 'password' => 'Vieja-Clave-1!', 'is_active' => true]);

        $this->artisan('admin:reset-password', ['email' => 'r@coupify.test', '--password' => 'Clave-Nueva-456!'])
            ->expectsOutputToContain('Contraseña restablecida')->assertSuccessful();

        $fresh = $admin->fresh();
        $this->assertTrue(Hash::check('Clave-Nueva-456!', $fresh->password));
        $this->assertNotNull($fresh->sessions_revoked_at);
    }

    public function test_admin_reset_password_can_reactivate_and_reset_two_factor(): void
    {
        $admin = AdminUser::factory()->create(['email' => 'r2@coupify.test', 'is_active' => false]);
        $admin->forceFill(['totp_secret' => 'ABC', 'totp_enabled' => true, 'totp_recovery_codes' => '[]'])->save();

        $this->artisan('admin:reset-password', ['email' => 'r2@coupify.test', '--password' => 'Clave-Nueva-456!'])
            ->expectsOutputToContain('sigue desactivada')->assertSuccessful();
        $this->assertFalse($admin->fresh()->is_active);

        $this->artisan('admin:reset-password', ['email' => 'r2@coupify.test', '--password' => 'Clave-Nueva-456!', '--activate' => true, '--reset-2fa' => true])
            ->assertSuccessful();
        $fresh = $admin->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertFalse((bool) $fresh->totp_enabled);
        $this->assertNull($fresh->totp_secret);
    }

    public function test_admin_reset_password_rejects_unknown_users_and_weak_passwords(): void
    {
        AdminUser::factory()->create(['email' => 'r3@coupify.test', 'password' => 'Vieja-Clave-1!']);

        $this->artisan('admin:reset-password', ['email' => 'nadie@coupify.test', '--password' => 'Clave-Nueva-456!'])
            ->expectsOutputToContain('No existe un administrador')->assertFailed();
        $this->artisan('admin:reset-password', ['email' => 'r3@coupify.test', '--password' => '12'])->assertFailed();

        $this->assertTrue(Hash::check('Vieja-Clave-1!', AdminUser::where('email', 'r3@coupify.test')->first()->password));
    }
}
