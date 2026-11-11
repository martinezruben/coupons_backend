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

class AdminTransactionExcelExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_export_transactions_excel(): void
    {
        $this->postJson(route('admin.transactions.excel.export'), [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-15',
        ])->assertUnauthorized();
    }

    public function test_admin_without_permission_gets_forbidden(): void
    {
        Role::create(['name' => 'empty', 'guard_name' => 'admin']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole('empty');

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.transactions.excel.export'), [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-15',
            ])
            ->assertForbidden();
    }

    public function test_validation_rejects_span_over_31_days_inclusive(): void
    {
        Permission::create(['name' => 'transactions.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'tx-viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo('transactions.view');

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.transactions.excel.export'), [
                'date_from' => '2026-01-01',
                'date_to' => '2026-02-01',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'El periodo no puede superar 31 días (desde y hasta inclusive).']);
    }

    public function test_admin_with_permission_downloads_xlsx(): void
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
            ->post(route('admin.transactions.excel.export'), [
                'date_from' => now()->subDay()->format('Y-m-d'),
                'date_to' => now()->addDay()->format('Y-m-d'),
            ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }
}
