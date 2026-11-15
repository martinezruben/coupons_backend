<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $super;

    private AdminUser $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = AdminUser::factory()->create(['name' => 'Sara Super']);
        $this->super->assignRole(Role::findOrCreate('super-admin', 'admin'));
        $this->target = AdminUser::factory()->create(['name' => 'Tomás Objetivo']);
    }

    public function test_a_direct_permission_gives_access_without_a_role_and_is_audited(): void
    {
        $this->actingAs($this->target, 'admin')->get(route('admin.screens.index', 'customers'))->assertForbidden();

        $this->actingAs($this->super, 'admin')->post(route('admin.rbac.users.update', $this->target), ['permissions' => ['customers.view', 'coupon_batches.generate']])
            ->assertRedirect(route('admin.rbac.users.edit', $this->target));

        $this->assertEqualsCanonicalizing(['customers.view', 'coupon_batches.generate'], $this->target->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->actingAs($this->target->fresh(), 'admin')->get(route('admin.screens.index', 'customers'))->assertOk();
        $this->get(route('admin.coupon-batches.create'))->assertOk();

        $log = AdminAuditLog::where('entity_type', 'AdminUser')->where('entity_id', (string) $this->target->id)->where('action', 'updated')->first();
        $this->assertNotNull($log);
        $this->assertEquals($this->super->id, $log->admin_user_id);
        $this->assertStringContainsString('customers.view', $log->changes['permisos_directos'][1]);
        $this->assertStringContainsString('Cambió los permisos específicos de Tomás Objetivo', $log->summary);
        $this->assertSame('admin.rbac.users.update', $log->route);
    }

    public function test_removing_a_direct_permission_revokes_it_and_is_audited(): void
    {
        $this->actingAs($this->super, 'admin');
        $url = route('admin.rbac.users.update', $this->target);
        $this->post($url, ['permissions' => ['customers.view', 'customers.edit']]);
        AdminAuditLog::query()->delete();

        $this->post($url, ['permissions' => ['customers.view']]);

        $this->assertSame(['customers.view'], $this->target->fresh()->getDirectPermissions()->pluck('name')->all());
        $log = AdminAuditLog::where('action', 'updated')->where('entity_type', 'AdminUser')->first();
        $this->assertStringContainsString('− customers.edit', $log->changes['permisos_directos'][0]);

        $before = AdminAuditLog::where('action', 'updated')->count();
        $this->post($url, ['permissions' => ['customers.view']]); // sin cambios: no ensucia la auditoría
        $this->assertSame($before, AdminAuditLog::where('action', 'updated')->count());
    }

    public function test_role_permissions_are_shown_as_inherited_and_not_duplicated(): void
    {
        $role = Role::findOrCreate('lectura', 'admin');
        Permission::findOrCreate('customers.view', 'admin');
        $role->givePermissionTo('customers.view');
        $this->target->assignRole($role);

        $html = $this->actingAs($this->super, 'admin')->get(route('admin.rbac.users.edit', $this->target))->assertOk()->assertSee('Permisos específicos de Tomás Objetivo')->getContent();
        $this->assertStringContainsString('Viene del rol: lectura', $html);

        // Guardar sin marcar nada no toca lo heredado y no crea permisos directos
        $this->post(route('admin.rbac.users.update', $this->target), ['permissions' => []]);
        $this->assertSame([], $this->target->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->assertTrue($this->target->fresh()->can('customers.view'));
    }

    public function test_unknown_permissions_are_rejected_and_other_direct_permissions_survive(): void
    {
        $this->actingAs($this->super, 'admin');
        $this->post(route('admin.rbac.users.update', $this->target), ['permissions' => ['hackear.todo']])->assertSessionHasErrors('permissions.0');

        Permission::findOrCreate('custom.extra', 'admin'); // permiso fuera de la matriz
        $this->target->givePermissionTo('custom.extra');
        $this->post(route('admin.rbac.users.update', $this->target), ['permissions' => ['customers.view']]);
        $this->assertTrue($this->target->fresh()->hasDirectPermission('custom.extra'));
    }

    public function test_only_super_admin_can_manage_user_permissions_and_super_admins_are_excluded(): void
    {
        $manager = AdminUser::factory()->create();
        Permission::findOrCreate('roles.edit', 'admin');
        $manager->givePermissionTo('roles.edit');

        $this->actingAs($manager, 'admin');
        $this->get(route('admin.rbac.users.index'))->assertForbidden();
        $this->get(route('admin.rbac.users.edit', $this->target))->assertForbidden();
        $this->post(route('admin.rbac.users.update', $this->target), ['permissions' => ['customers.view']])->assertForbidden();
        $this->assertSame([], $this->target->fresh()->getDirectPermissions()->all());

        $this->actingAs($this->super, 'admin');
        $this->get(route('admin.rbac.users.edit', $this->super))->assertForbidden();
        $this->post(route('admin.rbac.users.update', $this->super), ['permissions' => ['customers.view']])->assertForbidden();
    }

    public function test_the_list_and_menu_entry_exist_and_every_visit_is_audited(): void
    {
        $this->target->givePermissionTo(Permission::findOrCreate('customers.view', 'admin'));

        $this->actingAs($this->super, 'admin')->get(route('admin.rbac.users.index'))->assertOk()->assertSee('Tomás Objetivo')->assertSee('todos (super-admin)');
        $this->get(route('admin.dashboard'))->assertSee(route('admin.rbac.users.index'), false)->assertSee('Permisos por usuario');
        $this->get(route('admin.rbac.users.edit', $this->target))->assertOk();

        $this->assertTrue(AdminAuditLog::where('action', 'view')->where('route', 'admin.rbac.users.edit')->where('summary', 'Abrió los permisos específicos de un usuario')->exists());
    }
}
