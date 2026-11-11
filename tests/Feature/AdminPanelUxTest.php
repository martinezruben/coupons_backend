<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPanelUxTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = ['devices.edit', 'android_users.view', 'android_users.edit', 'licenses.view', 'audit_log.view', 'roles.edit'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'admin']);
        }
        $role = Role::firstOrCreate(['name' => 'ux-tester', 'guard_name' => 'admin']);
        $role->givePermissionTo($permissions);
        $this->admin = AdminUser::factory()->create();
        $this->admin->assignRole($role);
    }

    private function deviceWithLicense(string $fingerprint): Device
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => $fingerprint,
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        return $device;
    }

    public function test_device_name_can_be_edited_and_shown_in_grids(): void
    {
        $device = $this->deviceWithLicense('fp-name-edit');

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.screens.update', ['devices', $device->id]), [
                'name' => 'Caja Frontal 1',
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.screens.index', 'devices'));

        $this->assertSame('Caja Frontal 1', $device->fresh()->name);

        $html = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.screens.index', 'licenses'))->getContent();
        $this->assertStringContainsString('Caja Frontal 1', $html);
    }

    public function test_user_edit_shows_current_pin(): void
    {
        $user = User::factory()->create(['pin4_sha384' => User::pin4Sha384FromPlain('7391'), 'pin4_enc' => '7391']);

        $html = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.screens.edit', ['android-users', $user->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('PIN actual', $html);
        foreach (str_split('7391') as $digit) {
            $this->assertStringContainsString('>'.$digit.'</span>', $html);
        }
    }

    public function test_audit_log_detail_endpoint_returns_changes(): void
    {
        $log = AdminAuditLog::record('updated', 'Product', (string) Str::uuid(), [
            'price' => ['1.5', '2.5'],
        ]);

        $res = $this->actingAs($this->admin, 'admin')
            ->getJson(route('admin.audit-logs.show', $log->id))
            ->assertOk();

        $res->assertJsonPath('entity_type', 'Product')
            ->assertJsonPath('action', 'updated');
        $this->assertEquals('1.5', $res->json('changes.price.0'));
    }

    public function test_rbac_matrix_groups_screens_by_nav_section(): void
    {
        // La matriz de permisos es solo para super-admin
        $super = AdminUser::factory()->create();
        $super->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'admin']));

        $res = $this->actingAs($super, 'admin')
            ->get(route('admin.rbac.matrix.edit', Role::firstOrCreate(['name' => 'grp-role', 'guard_name' => 'admin'])))
            ->assertOk();

        $html = $res->getContent();
        // secciones con el mismo nombre que el menú
        foreach (['Infraestructura', 'Operación', 'Bitácora', 'Sistema'] as $section) {
            $this->assertStringContainsString($section, $html);
        }
    }
}
