<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\SystemParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithSystemSettingsEdit(): AdminUser
    {
        Permission::create(['name' => 'system_settings.view', 'guard_name' => 'admin']);
        Permission::create(['name' => 'system_settings.edit', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'sys-settings', 'guard_name' => 'admin']);
        $role->givePermissionTo(['system_settings.view', 'system_settings.edit']);

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    public function test_guest_cannot_access_system_settings(): void
    {
        $this->get(route('admin.system-settings.edit'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_without_permission_gets_forbidden(): void
    {
        Role::create(['name' => 'empty', 'guard_name' => 'admin']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole('empty');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-settings.edit'))
            ->assertForbidden();
    }

    public function test_admin_with_permission_can_view_and_update_settings(): void
    {
        $admin = $this->adminWithSystemSettingsEdit();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.system-settings.edit'))
            ->assertOk()
            ->assertSee('Parámetros del sistema');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.system-settings.update'), [
                'admin_password_min_length' => 10,
                'pos_password_min_length' => 6,
                'admin_max_failed_login_attempts' => 3,
                'admin_lockout_minutes' => 30,
                'admin_password_require_uppercase' => '1',
                'admin_password_require_lowercase' => '1',
                'admin_password_require_digit' => '1',
                'admin_password_require_symbol' => '1',
                'pos_password_require_uppercase' => '0',
                'pos_password_require_lowercase' => '1',
                'pos_password_require_digit' => '1',
                'pos_password_require_symbol' => '0',
            ])
            ->assertRedirect(route('admin.system-settings.edit'))
            ->assertSessionHas('status');

        $p = SystemParameter::query()->first();
        $this->assertSame(10, $p->admin_password_min_length);
        $this->assertSame(6, $p->pos_password_min_length);
        $this->assertSame(3, $p->admin_max_failed_login_attempts);
        $this->assertSame(30, $p->admin_lockout_minutes);
        $this->assertTrue($p->admin_password_require_symbol);
    }
}
