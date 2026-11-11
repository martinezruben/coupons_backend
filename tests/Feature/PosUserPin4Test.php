<?php

namespace Tests\Feature;

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

class PosUserPin4Test extends TestCase
{
    use RefreshDatabase;

    private function adminWithEdit(): AdminUser
    {
        Permission::firstOrCreate(['name' => 'android_users.view', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'android_users.edit', 'guard_name' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'pos-user-manager', 'guard_name' => 'admin']);
        $role->givePermissionTo(['android_users.view', 'android_users.edit']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    private function payload(array $overrides = []): array
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);

        return array_merge([
            'username' => 'caja'.Str::random(4),
            'password' => 'ClavePos2026!',
            'role' => 'CASHIER',
            'is_active' => '1',
            'location_id' => $location->id,
        ], $overrides);
    }

    public function test_pos_user_can_be_created_with_4_digit_pin(): void
    {
        $admin = $this->adminWithEdit();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), $this->payload([
                'pin4' => '7391',
            ]))
            ->assertRedirect(route('admin.screens.index', 'android-users'));

        $user = User::where('username', 'like', 'caja%')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->pin4_sha384);
        $this->assertNotSame('5678', $user->pin4_sha384, 'El PIN no debe guardarse en claro');
        $this->assertSame(User::pin4Sha384FromPlain('7391'), $user->pin4_sha384);
    }

    public function test_pin4_is_optional(): void
    {
        $admin = $this->adminWithEdit();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'android-users'), $this->payload())
            ->assertRedirect(route('admin.screens.index', 'android-users'));

        $user = User::where('username', 'like', 'caja%')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->pin4_sha384);
    }

    public function test_pin4_validation_rejects_bad_values(): void
    {
        $admin = $this->adminWithEdit();
        $this->actingAs($admin, 'admin');

        // no numérico / longitud incorrecta
        $this->post(route('admin.screens.store', 'android-users'), $this->payload(['pin4' => '12ab']))
            ->assertSessionHasErrors('pin4');
        $this->post(route('admin.screens.store', 'android-users'), $this->payload(['pin4' => '12345']))
            ->assertSessionHasErrors('pin4');

        // PINs demasiado comunes
        $this->post(route('admin.screens.store', 'android-users'), $this->payload(['pin4' => '1234']))
            ->assertSessionHasErrors('pin4');
        $this->post(route('admin.screens.store', 'android-users'), $this->payload(['pin4' => '0000']))
            ->assertSessionHasErrors('pin4');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_pull_includes_pin4_hash(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-pin4',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $withPin = User::factory()->create(['pin4_sha384' => User::pin4Sha384FromPlain('9876')]);
        $withoutPin = User::factory()->create();
        $withPin->locations()->attach($location->id);
        $withoutPin->locations()->attach($location->id);

        $token = $device->createToken('pin4')->plainTextToken;
        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk();

        $rows = collect($res->json('data.users'));
        $this->assertSame(User::pin4Sha384FromPlain('9876'), $rows->firstWhere('id', $withPin->id)['pin4']);
        $this->assertNull($rows->firstWhere('id', $withoutPin->id)['pin4']);
    }
}
