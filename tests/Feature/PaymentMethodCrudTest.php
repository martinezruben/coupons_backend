<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PaymentMethodCrudTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithEdit(): AdminUser
    {
        Permission::firstOrCreate(['name' => 'payment_methods.view', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'payment_methods.edit', 'guard_name' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'pm-manager', 'guard_name' => 'admin']);
        $role->givePermissionTo(['payment_methods.view', 'payment_methods.edit']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        return $admin;
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

    public function test_legacy_payment_methods_are_seeded(): void
    {
        foreach (['pm-cash' => 'Efectivo', 'pm-card' => 'Tarjeta', 'pm-transfer' => 'Transferencia', 'pm-other' => 'Otro'] as $id => $name) {
            $pm = PaymentMethod::find($id);
            $this->assertNotNull($pm, "Falta el método legacy {$id}");
            $this->assertSame($name, $pm->name);
        }
    }

    public function test_admin_can_create_custom_payment_method(): void
    {
        $admin = $this->adminWithEdit();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'payment-methods'), [
                'name' => 'Sinpe Móvil',
                'type' => 'TRANSFER',
                'color' => 'blue',
                'is_enabled' => '1',
            ])
            ->assertRedirect(route('admin.screens.index', 'payment-methods'));

        $pm = PaymentMethod::where('name', 'Sinpe Móvil')->first();
        $this->assertNotNull($pm);
        $this->assertSame('TRANSFER', $pm->type);
        $this->assertTrue((bool) $pm->is_enabled);
    }

    public function test_pull_returns_payment_methods_from_db_with_color(): void
    {
        $device = $this->deviceWithLicense('fp-pm');
        PaymentMethod::create(['name' => 'Sinpe Móvil', 'type' => 'TRANSFER', 'color' => 'blue', 'is_enabled' => true]);

        $token = $device->createToken('pm')->plainTextToken;
        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk();

        $methods = collect($res->json('data.paymentMethods'));

        $cash = $methods->firstWhere('id', 'pm-cash');
        $this->assertNotNull($cash, 'Los métodos legacy deben seguir viajando');
        $this->assertSame('Efectivo', $cash['name']);

        $sinpe = $methods->firstWhere('name', 'Sinpe Móvil');
        $this->assertNotNull($sinpe);
        $this->assertSame('TRANSFER', $sinpe['type']);
        $this->assertSame('blue', $sinpe['color']);
        $this->assertTrue($sinpe['isEnabled']);
    }

    public function test_deleted_payment_method_travels_as_tombstone(): void
    {
        $device = $this->deviceWithLicense('fp-pm-del');
        $pm = PaymentMethod::create(['name' => 'Temporal', 'type' => 'OTHER', 'is_enabled' => true]);

        $token = $device->createToken('pm-del')->plainTextToken;
        $ts = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->json('syncTimestamp');

        $this->travel(5)->seconds();

        $pm->delete();

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query([
                'device_fingerprint' => $device->device_fingerprint,
                'lastSyncTimestamp' => $ts,
            ]))
            ->assertOk();

        $row = collect($res->json('data.paymentMethods'))->firstWhere('id', $pm->id);
        $this->assertNotNull($row, 'El método eliminado debe llegar como tombstone');
        $this->assertNotNull($row['deletedAt']);
    }

    public function test_push_accepts_custom_payment_method_id(): void
    {
        $device = $this->deviceWithLicense('fp-pm-push');
        $user = User::factory()->create();
        $custom = PaymentMethod::create(['name' => 'Cripto', 'type' => 'OTHER', 'is_enabled' => true]);

        $token = $device->createToken('push')->plainTextToken;
        $payload = [
            'transactions' => [[
                'external_id' => 'EXT-PM-1',
                'location_id' => $device->location_id,
                'user_id' => $user->id,
                'turn_number' => 1,
                'status' => 'PAID',
                'total' => 10,
                'occurred_at' => now()->toIso8601String(),
                'items' => [],
                'payments' => [
                    ['payment_method' => $custom->id, 'amount' => 10],
                ],
            ]],
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/sync/push', $payload)
            ->assertOk();

        $tx = Transaction::where('external_id', 'EXT-PM-1')->first();
        $this->assertNotNull($tx);
        $this->assertSame($custom->id, $tx->payments->first()->payment_method);
    }
}
