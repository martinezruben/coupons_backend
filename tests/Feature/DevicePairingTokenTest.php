<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use App\Support\DevicePairingToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DevicePairingTokenTest extends TestCase
{
    use RefreshDatabase;

    private function actingLocationsAdmin(): AdminUser
    {
        foreach (['locations.view', 'locations.edit', 'locations.delete'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'admin']);
        }
        $role = Role::create(['name' => 'locations-tester', 'guard_name' => 'admin']);
        $role->givePermissionTo(['locations.view', 'locations.edit', 'locations.delete']);

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    public function test_guest_cannot_access_pairing_token(): void
    {
        $loc = Location::create(['name' => 'L', 'is_active' => true]);

        $this->getJson(route('admin.locations.pairing-token.show', $loc))->assertUnauthorized();
    }

    public function test_admin_without_locations_edit_cannot_show_pairing_token(): void
    {
        Permission::create(['name' => 'locations.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo('locations.view');
        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);
        $loc = Location::create(['name' => 'L', 'is_active' => true]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.locations.pairing-token.show', $loc))
            ->assertForbidden();
    }

    public function test_store_ensure_creates_six_digit_code(): void
    {
        Cache::flush();
        $admin = $this->actingLocationsAdmin();
        $loc = Location::create(['name' => 'L', 'is_active' => true]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $loc), ['action' => 'ensure']);

        $response->assertOk();
        $response->assertJsonStructure(['code', 'expires_at']);
        $code = $response->json('code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_store_ensure_returns_existing_until_expiry(): void
    {
        Cache::flush();
        $admin = $this->actingLocationsAdmin();
        $loc = Location::create(['name' => 'L', 'is_active' => true]);

        $first = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $loc), ['action' => 'ensure'])
            ->json('code');

        $second = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $loc), ['action' => 'ensure'])
            ->json('code');

        $this->assertSame($first, $second);
    }

    public function test_regenerate_returns_new_code(): void
    {
        Cache::flush();
        $admin = $this->actingLocationsAdmin();
        $loc = Location::create(['name' => 'L', 'is_active' => true]);

        $first = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $loc), ['action' => 'ensure'])
            ->json('code');

        $second = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $loc), ['action' => 'regenerate'])
            ->json('code');

        $this->assertNotSame($first, $second);
    }

    public function test_codes_are_independent_per_location(): void
    {
        Cache::flush();
        $admin = $this->actingLocationsAdmin();
        $a = Location::create(['name' => 'A', 'is_active' => true]);
        $b = Location::create(['name' => 'B', 'is_active' => true]);

        $codeA = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $a), ['action' => 'ensure'])
            ->json('code');

        $codeB = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.locations.pairing-token.store', $b), ['action' => 'ensure'])
            ->json('code');

        $this->assertNotSame($codeA, $codeB);
        $this->assertSame($a->id, DevicePairingToken::validateAndConsume($codeA));
        $this->assertNull(DevicePairingToken::getPayloadForLocation($a->id));
        $this->assertSame($b->id, DevicePairingToken::validateAndConsume($codeB));
    }
}
