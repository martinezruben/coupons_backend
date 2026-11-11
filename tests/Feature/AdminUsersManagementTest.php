<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminActive;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\SystemParameter;
use App\Services\AdminUserGuard;
use App\Support\AdminNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUsersManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(array $attrs = []): AdminUser
    {
        Role::findOrCreate('super-admin', 'admin');
        $admin = AdminUser::factory()->create($attrs + ['is_active' => true]);
        $admin->assignRole('super-admin');

        return $admin;
    }

    private function limitedAdmin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create(['is_active' => true]);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'name' => 'Nuevo Admin',
            'email' => 'nuevo@tapandgo.test',
            'is_active' => '1',
            'password' => 'Clave-Segura-123!',
        ];
    }

    // ---- Acceso: solo super-admin

    public function test_menu_entries_are_only_for_super_admins(): void
    {
        $super = $this->superAdmin();
        $labels = fn ($admin) => collect(AdminNavigation::groups($admin))->pluck('items')->flatten(1)->pluck('label')->all();

        $this->actingAs($super, 'admin');
        $this->assertContains('Usuarios administradores', $labels($super));
        $this->assertContains('Roles', $labels($super));

        // Ni con permisos «de pantalla» asignados se ve: no son permisos asignables
        $limited = $this->limitedAdmin('admin_users.view', 'admin_users.edit', 'roles.view', 'roles.edit');
        $this->actingAs($limited, 'admin');
        $this->assertNotContains('Usuarios administradores', $labels($limited));
        $this->assertNotContains('Roles', $labels($limited));
    }

    public function test_non_super_admins_get_403_even_with_matching_permissions(): void
    {
        $limited = $this->limitedAdmin('admin_users.view', 'admin_users.edit', 'admin_users.delete', 'roles.edit');
        $target = $this->superAdmin();

        $this->actingAs($limited, 'admin');
        $this->get(route('admin.screens.index', 'admin-users'))->assertForbidden();
        $this->get(route('admin.screens.create', 'admin-users'))->assertForbidden();
        $this->post(route('admin.screens.store', 'admin-users'), $this->payload())->assertForbidden();
        $this->put(route('admin.screens.update', ['admin-users', $target->id]), $this->payload())->assertForbidden();
        $this->post(route('admin.admin-users.revoke-sessions', $target))->assertForbidden();
        $this->post(route('admin.admin-users.reset-2fa', $target))->assertForbidden();
        $this->get(route('admin.roles.index'))->assertForbidden();
        $this->get(route('admin.rbac.matrix.index'))->assertForbidden();
    }

    public function test_super_admin_can_open_the_screens(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.screens.index', 'admin-users'))->assertOk()->assertSee('Usuarios administradores');
        $this->get(route('admin.screens.create', 'admin-users'))->assertOk()->assertSee('Generar contraseña');
        $this->get(route('admin.roles.index'))->assertOk();
    }

    // ---- Crear / editar

    public function test_new_records_default_to_active_in_the_form(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $html = $this->get(route('admin.screens.create', 'admin-users'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select id="f-is_active"[^>]*>\s*<option value="1"\s+selected/s', $html);
    }

    public function test_super_admin_creates_an_admin_user_with_roles(): void
    {
        $role = Role::findOrCreate('gerente', 'admin');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['role_names' => ['gerente']]))
            ->assertRedirect(route('admin.screens.index', 'admin-users'))
            ->assertSessionHasNoErrors();

        $created = AdminUser::where('email', 'nuevo@tapandgo.test')->first();
        $this->assertNotNull($created);
        $this->assertTrue(Hash::check('Clave-Segura-123!', $created->password));
        $this->assertTrue($created->is_active);
        $this->assertSame(['gerente'], $created->getRoleNames()->all());
        $this->assertNotNull(AdminAuditLog::where('entity_type', 'AdminUser')->where('entity_id', (string) $created->id)->where('action', 'updated')->first(), 'La asignación de roles debe quedar auditada');
        $this->assertTrue($role->exists);
    }

    public function test_validation_rejects_bad_email_duplicates_weak_password_and_unknown_roles(): void
    {
        $existing = AdminUser::factory()->create(['email' => 'ya@tapandgo.test']);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['email' => 'no-es-correo']))->assertSessionHasErrors('email');
        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['email' => 'ya@tapandgo.test']))->assertSessionHasErrors('email');
        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['password' => '123']))->assertSessionHasErrors('password');
        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['password' => '']))->assertSessionHasErrors('password');
        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['role_names' => ['no-existe']]))->assertSessionHasErrors('role_names.0');
        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['name' => '']))->assertSessionHasErrors('name');

        $this->assertSame(0, AdminUser::where('email', 'nuevo@tapandgo.test')->count());
        $this->assertTrue($existing->exists);
    }

    public function test_password_complexity_policy_is_enforced(): void
    {
        SystemParameter::query()->update(['admin_password_require_symbol' => true, 'admin_password_require_digit' => true]);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['password' => 'SoloLetrasLargas']))->assertSessionHasErrors('password');
        $this->post(route('admin.screens.store', 'admin-users'), $this->payload(['password' => 'Con-Simbolo-1']))->assertSessionHasNoErrors();
    }

    public function test_editing_without_password_keeps_it_and_roles_changes_are_audited(): void
    {
        Role::findOrCreate('gerente', 'admin');
        Role::findOrCreate('soporte', 'admin');
        $target = AdminUser::factory()->create(['email' => 'edit@tapandgo.test', 'password' => 'Original-123!', 'is_active' => true]);
        $target->assignRole('gerente');
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');

        $this->put(route('admin.screens.update', ['admin-users', $target->id]), [
            'name' => 'Nombre Nuevo', 'email' => 'edit@tapandgo.test', 'is_active' => '1', 'password' => '', 'role_names' => ['soporte'],
        ])->assertSessionHasNoErrors();

        $fresh = $target->fresh();
        $this->assertSame('Nombre Nuevo', $fresh->name);
        $this->assertTrue(Hash::check('Original-123!', $fresh->password));
        $this->assertSame(['soporte'], $fresh->getRoleNames()->all());
        $this->assertNull($fresh->sessions_revoked_at, 'Sin cambio de contraseña no se cierran sesiones');

        $audit = AdminAuditLog::where('action', 'updated')->where('entity_id', (string) $target->id)->get()->first(fn ($l) => isset($l->changes['roles']));
        $this->assertSame(['gerente', 'soporte'], $audit->changes['roles']);
    }

    public function test_changing_another_users_password_revokes_their_sessions(): void
    {
        $target = AdminUser::factory()->create(['email' => 'pw@tapandgo.test', 'password' => 'Original-123!', 'is_active' => true]);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.screens.update', ['admin-users', $target->id]), [
            'name' => $target->name, 'email' => 'pw@tapandgo.test', 'is_active' => '1', 'password' => 'Nueva-Clave-456!',
        ])->assertSessionHasNoErrors();

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check('Nueva-Clave-456!', $fresh->password));
        $this->assertNotNull($fresh->sessions_revoked_at);
    }

    // ---- Desactivar, no eliminar

    public function test_admin_users_cannot_be_deleted_only_deactivated(): void
    {
        $target = AdminUser::factory()->create(['is_active' => true]);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->delete(route('admin.screens.destroy', ['admin-users', $target->id]))->assertNotFound();
        $this->assertTrue(AdminUser::whereKey($target->id)->exists());

        $this->get(route('admin.screens.index', 'admin-users'))->assertOk()->assertDontSee('¿Eliminar este registro?');

        $this->put(route('admin.screens.update', ['admin-users', $target->id]), [
            'name' => $target->name, 'email' => $target->email, 'is_active' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_inactive_admin_cannot_log_in_and_gets_the_generic_error(): void
    {
        AdminUser::factory()->create(['email' => 'off@tapandgo.test', 'password' => 'Clave-Activa-1!', 'is_active' => false]);
        AdminUser::factory()->create(['email' => 'on@tapandgo.test', 'password' => 'Clave-Activa-1!', 'is_active' => true]);

        $this->post(route('admin.login.store'), ['email' => 'off@tapandgo.test', 'password' => 'Clave-Activa-1!'])
            ->assertSessionHasErrors(['email' => 'Credenciales inválidas.']);
        $this->assertGuest('admin');

        $this->post(route('admin.login.store'), ['email' => 'on@tapandgo.test', 'password' => 'Clave-Activa-1!'])
            ->assertRedirect();
        $this->assertAuthenticated('admin');
    }

    public function test_an_open_session_is_cut_when_the_account_is_deactivated(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk();

        $admin->update(['is_active' => false]);

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_an_open_session_is_cut_after_sessions_were_revoked(): void
    {
        $admin = $this->superAdmin();
        $startedBefore = now()->subMinutes(5)->timestamp;

        $this->withSession([EnsureAdminActive::SESSION_KEY => $startedBefore])->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))->assertOk();

        $admin->forceFill(['sessions_revoked_at' => now()])->save();

        $this->withSession([EnsureAdminActive::SESSION_KEY => $startedBefore])->actingAs($admin->fresh(), 'admin')
            ->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));

        // Una sesión iniciada después de la revocación sigue valiendo
        $this->withSession([EnsureAdminActive::SESSION_KEY => now()->addMinute()->timestamp])->actingAs($admin->fresh(), 'admin')
            ->get(route('admin.dashboard'))->assertOk();
    }

    // ---- Protecciones

    public function test_you_cannot_deactivate_yourself_or_drop_your_own_super_admin_role(): void
    {
        Role::findOrCreate('gerente', 'admin');
        $me = $this->superAdmin();
        $other = $this->superAdmin(); // hay otro super-admin: la protección es sobre uno mismo
        $this->actingAs($me, 'admin');

        $base = ['name' => $me->name, 'email' => $me->email];

        $this->put(route('admin.screens.update', ['admin-users', $me->id]), $base + ['is_active' => '0', 'role_names' => ['super-admin']])
            ->assertSessionHasErrors('is_active');
        $this->put(route('admin.screens.update', ['admin-users', $me->id]), $base + ['is_active' => '1', 'role_names' => ['gerente']])
            ->assertSessionHasErrors('role_names');

        $fresh = $me->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertTrue($fresh->isSuperAdmin());
        $this->assertTrue($other->exists);
    }

    public function test_the_guard_never_leaves_the_system_without_an_active_super_admin(): void
    {
        Role::findOrCreate('gerente', 'admin');
        $last = $this->superAdmin();
        $actor = AdminUser::factory()->create(['is_active' => true]);

        // Único super-admin activo: no se puede desactivar ni quitarle el rol
        foreach ([[['super-admin'], false], [['gerente'], true]] as [$roles, $active]) {
            try {
                AdminUserGuard::assertChangeAllowed($actor, $last, $roles, $active);
                $this->fail('Debió impedir dejar el sistema sin super-admin activo.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        // Un super-admin inactivo no cuenta como respaldo
        $this->superAdmin(['is_active' => false]);
        $this->expectException(ValidationException::class);
        AdminUserGuard::assertChangeAllowed($actor, $last, ['super-admin'], false);
    }

    public function test_the_guard_allows_the_change_when_another_super_admin_is_active(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $actor = AdminUser::factory()->create(['is_active' => true]);

        AdminUserGuard::assertChangeAllowed($actor, $first, ['super-admin'], false);
        AdminUserGuard::assertChangeAllowed($actor, $first, [], true);

        $this->assertTrue(AdminUserGuard::otherActiveSuperAdminExists($first));
        $this->assertTrue($second->exists);
    }

    // ---- Acciones de seguridad

    public function test_super_admin_can_reset_two_factor_of_another_user(): void
    {
        $target = AdminUser::factory()->create(['is_active' => true]);
        $target->forceFill(['totp_secret' => 'ABC', 'totp_enabled' => true, 'totp_recovery_codes' => '[]'])->save();
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.admin-users.reset-2fa', $target))->assertRedirect()->assertSessionHas('status');

        $fresh = $target->fresh();
        $this->assertFalse((bool) $fresh->totp_enabled);
        $this->assertNull($fresh->totp_secret);
        $this->assertNotNull($fresh->sessions_revoked_at);
        $this->assertNotNull(AdminAuditLog::where('action', 'two_factor_reset')->where('entity_id', (string) $target->id)->first());
    }

    public function test_you_cannot_reset_your_own_two_factor_this_way(): void
    {
        $me = $this->superAdmin();
        $me->forceFill(['totp_secret' => 'ABC', 'totp_enabled' => true])->save();
        $this->actingAs($me, 'admin');

        $this->post(route('admin.admin-users.reset-2fa', $me))->assertSessionHasErrors('security');
        $this->assertTrue((bool) $me->fresh()->totp_enabled);
    }

    public function test_revoke_sessions_is_audited_and_keeps_the_current_session_when_done_on_yourself(): void
    {
        $me = $this->superAdmin();
        $other = AdminUser::factory()->create();
        $this->actingAs($me, 'admin');

        $this->post(route('admin.admin-users.revoke-sessions', $other))->assertRedirect()->assertSessionHas('status');
        $this->assertNotNull($other->fresh()->sessions_revoked_at);
        $this->assertNotNull(AdminAuditLog::where('action', 'sessions_revoked')->where('entity_id', (string) $other->id)->first());

        $this->post(route('admin.admin-users.revoke-sessions', $me))->assertRedirect();
        $this->get(route('admin.dashboard'))->assertOk(); // la sesión actual sigue
    }

    public function test_grid_shows_roles_two_factor_and_last_login(): void
    {
        Role::findOrCreate('gerente', 'admin');
        $user = AdminUser::factory()->create(['name' => 'Gerente Uno', 'email' => 'g1@tapandgo.test']);
        $user->assignRole('gerente');
        $user->forceFill(['totp_enabled' => true])->save();
        AdminAuditLog::record('login', 'AdminUser', (string) $user->id, null, $user);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.screens.index', 'admin-users'))
            ->assertOk()->assertSee('Gerente Uno')->assertSee('gerente')->assertSee('Activada');

        $this->get(route('admin.screens.index', ['admin-users', 'filter' => ['is_active' => '0']]))
            ->assertOk()->assertDontSee('Gerente Uno');
    }

    public function test_edit_form_shows_security_actions(): void
    {
        $target = AdminUser::factory()->create();
        $target->forceFill(['totp_enabled' => true])->save();
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.screens.edit', ['admin-users', $target->id]))
            ->assertOk()->assertSee('Cerrar sus sesiones')->assertSee('Reiniciar verificación en 2 pasos');
    }
}
