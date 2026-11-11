<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Location;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTransactionLineItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_transaction_line_items_json(): void
    {
        $location = Location::create([
            'name' => 'Test Loc',
            'address' => 'X',
            'latitude' => 0,
            'longitude' => 0,
            'is_active' => true,
        ]);
        $device = Device::create([
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-test',
            'name' => 'Caja',
            'is_enabled' => true,
            'registered_at' => now(),
        ]);
        $user = User::factory()->create();
        $tx = Transaction::create([
            'external_id' => 'EXT-1',
            'location_id' => $location->id,
            'device_id' => $device->id,
            'shift_id' => null,
            'user_id' => $user->id,
            'turn_number' => 1,
            'status' => 'PAID',
            'total' => 10.00,
            'occurred_at' => now(),
            'is_synced' => false,
            'synced_at' => null,
        ]);

        $this->getJson(route('admin.transactions.line-items', $tx))
            ->assertUnauthorized();
    }

    public function test_admin_with_permission_receives_items_and_payments(): void
    {
        Permission::create(['name' => 'transactions.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'tx-viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo('transactions.view');

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        $location = Location::create([
            'name' => 'Test Loc',
            'address' => 'X',
            'latitude' => 0,
            'longitude' => 0,
            'is_active' => true,
        ]);
        $device = Device::create([
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-test',
            'name' => 'Caja',
            'is_enabled' => true,
            'registered_at' => now(),
        ]);
        $user = User::factory()->create();
        $tx = Transaction::create([
            'external_id' => 'EXT-1',
            'location_id' => $location->id,
            'device_id' => $device->id,
            'shift_id' => null,
            'user_id' => $user->id,
            'turn_number' => 1,
            'status' => 'PAID',
            'total' => 10.00,
            'occurred_at' => now(),
            'is_synced' => false,
            'synced_at' => null,
        ]);
        TransactionItem::create([
            'transaction_id' => $tx->id,
            'product_id' => null,
            'product_name' => 'Item A',
            'product_sku' => 'SKU-A',
            'qty' => 2,
            'unit_price' => 5,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 10,
        ]);
        TransactionPayment::create([
            'transaction_id' => $tx->id,
            'payment_method' => 'CASH',
            'amount' => 10,
            'reference' => null,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.transactions.line-items', $tx));

        $response->assertOk()
            ->assertJsonPath('external_id', 'EXT-1')
            ->assertJsonPath('items.0.product_name', 'Item A')
            ->assertJsonPath('payments.0.payment_method', 'Efectivo');
    }

    public function test_admin_without_permission_gets_forbidden(): void
    {
        Role::create(['name' => 'empty', 'guard_name' => 'admin']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole('empty');

        $location = Location::create([
            'name' => 'Test Loc',
            'address' => 'X',
            'latitude' => 0,
            'longitude' => 0,
            'is_active' => true,
        ]);
        $device = Device::create([
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-test',
            'name' => 'Caja',
            'is_enabled' => true,
            'registered_at' => now(),
        ]);
        $user = User::factory()->create();
        $tx = Transaction::create([
            'external_id' => 'EXT-1',
            'location_id' => $location->id,
            'device_id' => $device->id,
            'shift_id' => null,
            'user_id' => $user->id,
            'turn_number' => 1,
            'status' => 'PAID',
            'total' => 10.00,
            'occurred_at' => now(),
            'is_synced' => false,
            'synced_at' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.transactions.line-items', $tx))
            ->assertForbidden();
    }
}
