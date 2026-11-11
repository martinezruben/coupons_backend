<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Support\AdminRbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PulseAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_admin_without_permission_cannot_view_pulse(): void
    {
        $admin = AdminUser::factory()->create(['is_active' => true]);

        $this->assertFalse(Gate::forUser($admin)->allows('viewPulse'));
        $this->actingAs($admin, 'admin')->get('/pulse')->assertForbidden();
    }

    public function test_pulse_view_permission_or_super_admin_grants_access(): void
    {
        Permission::findOrCreate('pulse.view', 'admin');
        $withPermission = AdminUser::factory()->create(['is_active' => true]);
        $withPermission->givePermissionTo('pulse.view');

        $superAdmin = AdminUser::factory()->create(['is_active' => true]);
        $superAdmin->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $this->assertTrue(Gate::forUser($withPermission)->allows('viewPulse'));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewPulse'));
        $this->assertContains('pulse.view', AdminRbac::allManagedPermissionNames());
    }
}
