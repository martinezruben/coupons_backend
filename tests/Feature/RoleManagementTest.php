<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        Role::findOrCreate('super-admin', 'admin');
        $admin = AdminUser::factory()->create(['is_active' => true]);
        $admin->assignRole('super-admin');

        return $admin;
    }

    public function test_index_lists_roles_with_user_and_permission_counts(): void
    {
        Permission::findOrCreate('products.view', 'admin');
        Permission::findOrCreate('products.edit', 'admin');
        $gerente = Role::findOrCreate('gerente', 'admin');
        $gerente->givePermissionTo(['products.view', 'products.edit']);
        AdminUser::factory()->create()->assignRole($gerente);
        AdminUser::factory()->create()->assignRole($gerente);

        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('gerente')
            ->assertSee('Super-admin')
            ->assertViewHas('roles', function ($roles) {
                $g = $roles->firstWhere('name', 'gerente');

                return $g->users_count === 2 && $g->permissions_count === 2 && ! $g->is_protected
                    && $roles->firstWhere('name', 'super-admin')->is_protected;
            });
    }

    public function test_create_role_validates_the_name(): void
    {
        Role::findOrCreate('existente', 'admin');
        $this->actingAs($this->superAdmin(), 'admin');

        foreach (['', 'ab', 'Con Espacios', 'MAYUSCULAS', 'con_guion_bajo', '-empieza', 'termina-', 'existente', str_repeat('a', 51)] as $bad) {
            $this->post(route('admin.roles.store'), ['name' => $bad])->assertSessionHasErrors('name');
        }
        $this->post(route('admin.roles.store'), ['name' => 'soporte-tecnico'])->assertSessionHasNoErrors();

        $this->assertSame(['soporte-tecnico'], Role::where('name', 'soporte-tecnico')->pluck('name')->all());
        $this->assertSame('admin', Role::where('name', 'soporte-tecnico')->first()->guard_name);
    }

    public function test_create_role_can_copy_permissions_and_is_audited(): void
    {
        Permission::findOrCreate('products.view', 'admin');
        Permission::findOrCreate('devices.view', 'admin');
        $source = Role::findOrCreate('ops-viewer', 'admin');
        $source->givePermissionTo(['products.view', 'devices.view']);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.roles.store'), ['name' => 'copia-viewer', 'copy_from' => $source->id])
            ->assertSessionHasNoErrors();

        $copy = Role::where('name', 'copia-viewer')->first();
        $this->assertEqualsCanonicalizing(['products.view', 'devices.view'], $copy->permissions->pluck('name')->all());
        $this->assertNotNull(AdminAuditLog::where('entity_type', 'Role')->where('action', 'created')->where('entity_id', (string) $copy->id)->first());
    }

    public function test_copy_from_rejects_unknown_roles(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.roles.store'), ['name' => 'nuevo-rol', 'copy_from' => 99999])->assertSessionHasErrors('copy_from');
        $this->assertSame(0, Role::where('name', 'nuevo-rol')->count());
    }

    public function test_rename_keeps_users_and_permissions_and_is_audited(): void
    {
        Permission::findOrCreate('products.view', 'admin');
        $role = Role::findOrCreate('viejo-nombre', 'admin');
        $role->givePermissionTo('products.view');
        $user = AdminUser::factory()->create();
        $user->assignRole($role);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.roles.update', $role), ['name' => 'nombre-nuevo'])->assertSessionHasNoErrors();

        $this->assertSame('nombre-nuevo', $role->fresh()->name);
        $this->assertSame(['nombre-nuevo'], $user->fresh()->getRoleNames()->all());
        $this->assertTrue($user->fresh()->hasPermissionTo('products.view'));
        $audit = AdminAuditLog::where('entity_type', 'Role')->where('entity_id', (string) $role->id)->latest('created_at')->first();
        $this->assertSame(['viejo-nombre', 'nombre-nuevo'], $audit->changes['name']);

        // Conservar el mismo nombre no es un conflicto
        $this->put(route('admin.roles.update', $role), ['name' => 'nombre-nuevo'])->assertSessionHasNoErrors();
    }

    public function test_delete_is_blocked_while_users_have_the_role(): void
    {
        $role = Role::findOrCreate('con-usuarios', 'admin');
        AdminUser::factory()->create()->assignRole($role);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->delete(route('admin.roles.destroy', $role))->assertSessionHasErrors('role');
        $this->assertTrue(Role::whereKey($role->id)->exists());
    }

    public function test_an_unused_role_can_be_deleted_and_it_is_audited(): void
    {
        $role = Role::findOrCreate('sin-usuarios', 'admin');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->delete(route('admin.roles.destroy', $role))->assertRedirect(route('admin.roles.index'));

        $this->assertFalse(Role::whereKey($role->id)->exists());
        $this->assertNotNull(AdminAuditLog::where('entity_type', 'Role')->where('action', 'deleted')->where('entity_id', (string) $role->id)->first());
    }

    public function test_the_super_admin_role_cannot_be_renamed_deleted_or_have_its_permissions_edited(): void
    {
        $super = Role::findOrCreate('super-admin', 'admin');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.roles.edit', $super))->assertForbidden();
        $this->put(route('admin.roles.update', $super), ['name' => 'otro-nombre'])->assertForbidden();
        $this->delete(route('admin.roles.destroy', $super))->assertForbidden();
        $this->get(route('admin.rbac.matrix.edit', $super))->assertForbidden();
        $this->post(route('admin.rbac.matrix.update', $super), ['permissions' => []])->assertForbidden();

        $this->assertSame('super-admin', $super->fresh()->name);
    }

    public function test_matrix_index_goes_to_roles_when_there_are_no_editable_roles(): void
    {
        $this->actingAs($this->superAdmin(), 'admin'); // solo existe el rol super-admin

        $this->get(route('admin.rbac.matrix.index'))->assertRedirect(route('admin.roles.index'));

        Role::findOrCreate('zeta', 'admin');
        Role::findOrCreate('alfa', 'admin');
        $this->get(route('admin.rbac.matrix.index'))->assertRedirect(route('admin.rbac.matrix.edit', Role::where('name', 'alfa')->first()));
    }

    public function test_a_new_role_can_be_assigned_to_an_admin_user_from_the_user_form(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $this->post(route('admin.roles.store'), ['name' => 'auditor']);

        $this->post(route('admin.screens.store', 'admin-users'), [
            'name' => 'Aud Itor', 'email' => 'auditor@coupify.test', 'is_active' => '1', 'password' => 'Clave-Segura-123!', 'role_names' => ['auditor'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['auditor'], AdminUser::where('email', 'auditor@coupify.test')->first()->getRoleNames()->all());
    }

    public function test_matrix_only_lists_editable_roles_and_links_to_role_management(): void
    {
        Role::findOrCreate('gerente', 'admin');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.rbac.matrix.edit', Role::where('name', 'gerente')->first()))
            ->assertOk()
            ->assertSee('Gestionar roles')
            ->assertViewHas('roles', fn ($roles) => $roles->pluck('name')->all() === ['gerente']);
    }
}
