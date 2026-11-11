<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAndroidUserStoreTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithAndroidUsersEdit(): AdminUser
    {
        Permission::create(['name' => 'android_users.edit', 'guard_name' => 'admin']);
        Permission::create(['name' => 'android_users.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'android-tester', 'guard_name' => 'admin']);
        $role->givePermissionTo(['android_users.edit', 'android_users.view']);

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    public function test_store_android_user_requires_username_and_valid_role(): void
    {
        $admin = $this->adminWithAndroidUsersEdit();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), [
                'username' => '',
                'full_name' => 'Test',
                'role' => 'CASHIER',
                'is_active' => '1',
                'password' => 'password12',
            ])
            ->assertSessionHasErrors(['username']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), [
                'username' => 'cajero1',
                'full_name' => 'Test',
                'role' => 'INVALID_ROLE',
                'is_active' => '1',
                'password' => 'password12',
            ])
            ->assertSessionHasErrors(['role']);
    }

    public function test_store_android_user_creates_pos_user(): void
    {
        $admin = $this->adminWithAndroidUsersEdit();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), [
                'username' => 'cajero_demo',
                'full_name' => 'Cajero Demo',
                'role' => 'CASHIER',
                'is_active' => '1',
                'password' => 'password12',
            ])
            ->assertRedirect(route('admin.screens.index', 'android-users'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'username' => 'cajero_demo',
            'full_name' => 'Cajero Demo',
            'role' => 'CASHIER',
        ]);

        $u = User::query()->where('username', 'cajero_demo')->first();
        $this->assertNotNull($u);
        $this->assertTrue(Hash::check('password12', $u->password));
        $this->assertSame(User::pinSha384FromPlain('password12'), $u->pin_sha384);
    }

    public function test_store_android_user_rejects_duplicate_username(): void
    {
        $admin = $this->adminWithAndroidUsersEdit();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), [
                'username' => 'user_a',
                'full_name' => 'A',
                'role' => 'CASHIER',
                'is_active' => '1',
                'password' => 'password12',
            ])
            ->assertRedirect(route('admin.screens.index', 'android-users'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), [
                'username' => 'user_a',
                'full_name' => 'B',
                'role' => 'MANAGER',
                'is_active' => '1',
                'password' => 'password12',
            ])
            ->assertSessionHasErrors(['username']);

        $this->assertSame(1, User::query()->count());
    }

    public function test_store_android_user_persists_location_and_syncs_pivot(): void
    {
        $admin = $this->adminWithAndroidUsersEdit();
        $location = Location::create([
            'name' => 'Sucursal Test',
            'address' => 'Calle 1',
            'latitude' => 0,
            'longitude' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), [
                'username' => 'cajero_loc',
                'full_name' => 'Con localidad',
                'role' => 'CASHIER',
                'is_active' => '1',
                'password' => 'password12',
                'location_id' => $location->id,
            ])
            ->assertRedirect(route('admin.screens.index', 'android-users'))
            ->assertSessionHas('status');

        $u = User::query()->where('username', 'cajero_loc')->first();
        $this->assertNotNull($u);
        $this->assertSame($location->id, $u->location_id);
        $this->assertTrue($u->locations()->where('locations.id', $location->id)->exists());
    }

    public function test_validation_messages_are_in_spanish_with_field_labels(): void
    {
        $this->actingAs($this->adminWithAndroidUsersEdit(), 'admin')
            ->post(route('admin.screens.store', 'android-users'), [
                'username' => '',
                'full_name' => 'Test',
                'role' => 'CASHIER',
                'is_active' => '1',
                'password' => 'password12',
            ])
            ->assertSessionHasErrors(['username' => 'El campo Usuario es obligatorio.']);
    }

    public function test_pagination_is_in_spanish(): void
    {
        $this->actingAs($this->adminWithAndroidUsersEdit(), 'admin')
            ->get(route('admin.screens.index', 'android-users'))
            ->assertOk()
            ->assertDontSee('Showing')
            ->assertSee('Mostrando');
    }
}
