<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminLicenseValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_license_store_requires_valid_from_and_valid_to(): void
    {
        Permission::create(['name' => 'licenses.edit', 'guard_name' => 'admin']);
        Permission::create(['name' => 'licenses.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'lic-admin', 'guard_name' => 'admin']);
        $role->givePermissionTo(['licenses.edit', 'licenses.view']);

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'L', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-1',
            'is_enabled' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'licenses'), [
                'device_id' => $device->id,
                'status' => 'ACTIVE',
            ])
            ->assertSessionHasErrors(['valid_from', 'valid_to']);
    }

    public function test_license_store_persists_valid_from_and_valid_to(): void
    {
        Permission::create(['name' => 'licenses.edit', 'guard_name' => 'admin']);
        Permission::create(['name' => 'licenses.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'lic-admin', 'guard_name' => 'admin']);
        $role->givePermissionTo(['licenses.edit', 'licenses.view']);

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'L', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-2',
            'is_enabled' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'licenses'), [
                'device_id' => $device->id,
                'valid_from' => '2026-01-01T08:30',
                'valid_to' => '2027-06-15T18:00',
                'status' => 'ACTIVE',
            ])
            ->assertRedirect(route('admin.screens.index', 'licenses'));

        $license = License::query()->where('device_id', $device->id)->first();
        $this->assertNotNull($license);
        $this->assertSame($license->id, $license->license_key);
        $this->assertSame('2026-01-01 08:30:00', $license->valid_from->format('Y-m-d H:i:s'));
        $this->assertSame('2027-06-15 18:00:00', $license->valid_to->format('Y-m-d H:i:s'));
    }

    public function test_license_update_persists_valid_from_and_valid_to(): void
    {
        Permission::create(['name' => 'licenses.edit', 'guard_name' => 'admin']);
        Permission::create(['name' => 'licenses.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'lic-admin', 'guard_name' => 'admin']);
        $role->givePermissionTo(['licenses.edit', 'licenses.view']);

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'L', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-upd',
            'is_enabled' => true,
        ]);

        $license = License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subMonth(),
            'valid_to' => now()->addMonth(),
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.screens.update', ['licenses', $license->getKey()]), [
                'device_id' => $device->id,
                'valid_from' => '2028-03-10T09:15',
                'valid_to' => '2029-12-01T12:45',
                'status' => 'ACTIVE',
            ])
            ->assertRedirect(route('admin.screens.index', 'licenses'));

        $license->refresh();
        $this->assertSame($license->id, $license->license_key);
        $this->assertSame('2028-03-10 09:15:00', $license->valid_from->format('Y-m-d H:i:s'));
        $this->assertSame('2029-12-01 12:45:00', $license->valid_to->format('Y-m-d H:i:s'));
    }
}
