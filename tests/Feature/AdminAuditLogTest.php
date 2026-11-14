<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'audit_log.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'audit-viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo('audit_log.view');
        $this->admin = AdminUser::factory()->create();
        $this->admin->assignRole($role);
        $this->actingAs($this->admin, 'admin');
    }

    public function test_location_creation_is_audited(): void
    {
        $location = Location::create(['name' => 'Sede Nueva', 'is_active' => true]);

        $log = AdminAuditLog::query()
            ->where('action', 'created')
            ->where('entity_type', 'Location')
            ->where('entity_id', $location->id)
            ->first();

        $this->assertNotNull($log, 'Debe registrar la creación de la localidad');
        $this->assertSame((string) $this->admin->id, (string) $log->admin_user_id);
        $this->assertSame($this->admin->name, $log->admin_name);
    }

    public function test_location_update_records_field_changes(): void
    {
        $location = Location::create(['name' => 'Sede Vieja', 'is_active' => true]);
        AdminAuditLog::query()->delete();

        $location->update(['name' => 'Sede Renombrada']);

        $log = AdminAuditLog::query()->where('action', 'updated')->where('entity_type', 'Location')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertArrayHasKey('name', $log->changes);
        $this->assertEquals('Sede Vieja', $log->changes['name'][0]);
        $this->assertEquals('Sede Renombrada', $log->changes['name'][1]);
    }

    public function test_sensitive_attributes_are_not_recorded(): void
    {
        $admin = AdminUser::factory()->create(['password' => bcrypt('Secret0!xyz')]);
        AdminAuditLog::query()->delete();

        $admin->update(['password' => bcrypt('Nueva0!clave'), 'name' => 'Nuevo Nombre']);

        $log = AdminAuditLog::query()->where('action', 'updated')->where('entity_type', 'AdminUser')->first();
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('password', $log->changes ?? []);
        $this->assertArrayHasKey('name', $log->changes);
    }

    public function test_delete_is_audited(): void
    {
        $location = Location::create(['name' => 'Temporal', 'is_active' => true]);
        AdminAuditLog::query()->delete();

        $location->delete();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'deleted',
            'entity_type' => 'Location',
            'entity_id' => $location->id,
        ]);
    }

    public function test_admin_login_is_audited(): void
    {
        // el setUp ya autenticó al admin; cerrar sesión para poder hacer login
        auth('admin')->logout();
        AdminAuditLog::query()->delete();

        $this->post('/admin/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'login',
            'entity_type' => 'AdminUser',
            'admin_user_id' => $this->admin->id,
        ]);
    }

    public function test_rbac_matrix_permission_changes_are_audited(): void
    {
        // La matriz de permisos es solo para super-admin
        Permission::firstOrCreate(['name' => 'licenses.view', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'licenses.edit', 'guard_name' => 'admin']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'admin']));
        $role = Role::create(['name' => 'rbac-target', 'guard_name' => 'admin']);
        $role->givePermissionTo('licenses.view');

        $this->actingAs($this->admin, 'admin')
            ->post(route('admin.rbac.matrix.update', $role), [
                'permissions' => ['licenses.view', 'licenses.edit'],
            ])
            ->assertRedirect();

        $log = AdminAuditLog::query()
            ->where('entity_type', 'Role')
            ->where('entity_id', $role->id)
            ->first();
        $this->assertNotNull($log, 'El cambio de permisos del rol debe quedar auditado');
        $changes = $log->changes['permissions'];
        $this->assertContains('licenses.edit', array_column($changes[0], 'name'));
        $this->assertEmpty($changes[1], 'Ningún permiso fue removido');
    }

    public function test_pairing_token_generation_is_audited(): void
    {
        Permission::firstOrCreate(['name' => 'locations.edit', 'guard_name' => 'admin']);
        $location = Location::create(['id' => (string) \Str::uuid(), 'name' => 'Sede Token', 'is_active' => true]);
        $this->admin->givePermissionTo('locations.edit');
        AdminAuditLog::query()->delete();

        $this->actingAs($this->admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $location), [
                'action' => 'regenerate',
            ])->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'created',
            'entity_type' => 'PairingToken',
            'entity_id' => $location->id,
        ]);
    }

    public function test_last_sync_at_changes_are_not_audited(): void
    {
        $location = Location::create(['id' => (string) \Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) \Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-audit-noise',
            'is_enabled' => true,
        ]);
        AdminAuditLog::query()->delete();

        $device->update(['last_sync_at' => now()]);

        $log = AdminAuditLog::query()
            ->where('entity_type', 'Device')
            ->where('entity_id', $device->id)
            ->where('action', 'updated')
            ->first();
        $this->assertNull($log, 'last_sync_at es un cambio operacional y no debe auditarse');
    }

    public function test_audit_log_screen_lists_entries_with_filters(): void
    {
        $location = Location::create(['name' => 'Sede Lista', 'is_active' => true]);

        $res = $this->get(route('admin.audit.index'));
        $res->assertOk()->assertSee($location->id)->assertSee('Creación');

        $res = $this->get(route('admin.audit.index', ['entity_type' => 'Location']));
        $res->assertOk()->assertSee('Localidad');
    }
}
