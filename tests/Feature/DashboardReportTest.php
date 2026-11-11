<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_basic_kpis(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-report-1',
            'is_enabled' => true,
        ]);

        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $user = User::factory()->create();
        $user->locations()->attach($location->id);

        $tx = Transaction::create([
            'id' => (string) Str::uuid(),
            'external_id' => 'ext-kpi-1',
            'location_id' => $location->id,
            'device_id' => $device->id,
            'shift_id' => null,
            'user_id' => $user->id,
            'turn_number' => 1,
            'status' => 'PAID',
            'total' => 50,
            'occurred_at' => now(),
            'is_synced' => true,
            'synced_at' => now(),
        ]);

        TransactionPayment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx->id,
            'payment_method' => 'CASH',
            'amount' => 50,
        ]);

        $token = $device->createToken('report-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/dashboard')
            ->assertOk()
            ->assertJsonPath('sales_today', 50)
            ->assertJsonPath('tickets_today', 1)
            ->assertJsonPath('avg_ticket_today', 50)
            ->assertJsonPath('online_sales_today', 50);
    }

    /**
     * Venta a las 23:00 locales (que en UTC ya es el día siguiente) debe
     * contarse en el día LOCAL:  el backend opera en GMT-4.
     */
    public function test_late_evening_sale_counts_for_local_day(): void
    {
        // 23:00 locales del 4 de septiembre = 03:00 UTC del 5 de septiembre
        $this->travel('2026-09-04 23:00:00');

        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-report-tz',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);
        $user = User::factory()->create();
        $user->locations()->attach($location->id);

        Transaction::create([
            'id' => (string) Str::uuid(),
            'external_id' => 'ext-tz-late',
            'location_id' => $location->id,
            'device_id' => $device->id,
            'shift_id' => null,
            'user_id' => $user->id,
            'turn_number' => 1,
            'status' => 'PAID',
            'total' => 80,
            'occurred_at' => now(),
            'is_synced' => true,
        ]);

        $token = $device->createToken('tz-test')->plainTextToken;

        // API: ventas de hoy (local) incluyen la venta de las 23:00
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/reports/dashboard')
            ->assertOk()
            ->assertJsonPath('sales_today', 80)
            ->assertJsonPath('tickets_today', 1);

        // Panel: KPI "Ventas hoy" del dashboard admin también en local
        Permission::firstOrCreate(['name' => 'dashboard.view', 'guard_name' => 'admin']);
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('dashboard.view');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('$80.00');
    }
}
