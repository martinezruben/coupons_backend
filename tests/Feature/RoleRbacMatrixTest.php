<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleRbacMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_rbac_matrix(): void
    {
        $role = Role::create(['name' => 'test-role', 'guard_name' => 'admin']);

        $this->get(route('admin.rbac.matrix.edit', $role))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_who_is_not_super_admin_cannot_access_matrix(): void
    {
        Role::create(['name' => 'empty', 'guard_name' => 'admin']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole('empty');

        $role = Role::create(['name' => 'target', 'guard_name' => 'admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.rbac.matrix.edit', $role))
            ->assertForbidden();
    }

    public function test_the_roles_edit_permission_alone_no_longer_opens_the_matrix(): void
    {
        Permission::create(['name' => 'roles.edit', 'guard_name' => 'admin']);
        $editorRole = Role::create(['name' => 'rbac-editor', 'guard_name' => 'admin']);
        $editorRole->givePermissionTo('roles.edit');
        $admin = AdminUser::factory()->create();
        $admin->assignRole($editorRole);
        $target = Role::create(['name' => 'ops-test', 'guard_name' => 'admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.rbac.matrix.edit', $target))->assertForbidden();
        $this->actingAs($admin, 'admin')->post(route('admin.rbac.matrix.update', $target), ['permissions' => []])->assertForbidden();
    }

    public function test_super_admin_can_view_and_update_permissions(): void
    {
        Permission::create(['name' => 'locations.view', 'guard_name' => 'admin']);
        Permission::create(['name' => 'locations.edit', 'guard_name' => 'admin']);

        $admin = AdminUser::factory()->create();
        $admin->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $target = Role::create(['name' => 'ops-test', 'guard_name' => 'admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.rbac.matrix.edit', $target))
            ->assertOk()
            ->assertSee('Permisos por rol');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.rbac.matrix.update', $target), [
                'permissions' => ['locations.view', 'locations.edit'],
            ])
            ->assertRedirect(route('admin.rbac.matrix.edit', $target))
            ->assertSessionHas('status');

        $target->refresh();
        $this->assertTrue($target->hasPermissionTo('locations.view'));
        $this->assertTrue($target->hasPermissionTo('locations.edit'));
        $this->assertFalse($target->hasPermissionTo('locations.delete'));
    }

    public function test_index_redirects_to_first_editable_role(): void
    {
        $admin = AdminUser::factory()->create();
        $admin->assignRole(Role::findOrCreate('super-admin', 'admin'));

        Role::create(['name' => 'alpha', 'guard_name' => 'admin']);
        $second = Role::create(['name' => 'beta', 'guard_name' => 'admin']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.rbac.matrix.index'))
            ->assertRedirect(route('admin.rbac.matrix.edit', Role::where('name', 'alpha')->first()));
    }
}
