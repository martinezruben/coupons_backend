<?php

namespace Tests\Feature;

use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_kpis_aggregates_all_periods_in_one_call(): void
    {
        $location = Location::factory()->create();

        // Crear ventas en diferentes períodos
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // Hoy
        Transaction::factory()->create(['status' => 'PAID', 'total' => 100, 'occurred_at' => now()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 50, 'occurred_at' => now()]);

        // Ayer
        Transaction::factory()->create(['status' => 'PAID', 'total' => 80, 'occurred_at' => now()->subDay()]);

        // Hace 5 días (dentro de 7d)
        Transaction::factory()->create(['status' => 'PAID', 'total' => 200, 'occurred_at' => now()->subDays(5)]);

        $service = new DashboardService;
        $kpis = $service->getKpis();

        // Una sola llamada al servicio debe tener calculado todo
        $this->assertSame(150.0, $kpis['salesToday']);
        $this->assertSame(2, $kpis['txToday']);
        $this->assertSame(80.0, $kpis['salesYesterday']);
        $this->assertSame(1, $kpis['txYesterday']);
        $this->assertSame(430.0, $kpis['sales7d']); // 100+50+80+200
        $this->assertSame(430.0, $kpis['sales30d']);
    }

    public function test_service_respects_location_filter(): void
    {
        $loc1 = Location::factory()->create();
        $loc2 = Location::factory()->create();

        Transaction::factory()->create(['status' => 'PAID', 'total' => 100, 'location_id' => $loc1->id, 'occurred_at' => now()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 200, 'location_id' => $loc2->id, 'occurred_at' => now()]);

        $service1 = new DashboardService($loc1->id);
        $service2 = new DashboardService($loc2->id);
        $serviceAll = new DashboardService;

        $this->assertSame(100.0, $service1->getKpis()['salesToday']);
        $this->assertSame(200.0, $service2->getKpis()['salesToday']);
        $this->assertSame(300.0, $serviceAll->getKpis()['salesToday']);
    }

    public function test_locations_in_contingency(): void
    {
        $loc1 = Location::factory()->create(['is_active' => true, 'contingency_started_at' => now()->subHours(2)]);
        $loc2 = Location::factory()->create(['is_active' => true, 'contingency_started_at' => null]);
        Location::factory()->create(['is_active' => false, 'contingency_started_at' => now()->subHours(1)]);

        $service = new DashboardService;
        $contingencies = $service->getLocationsInContingency();

        $this->assertCount(1, $contingencies);
        $this->assertSame($loc1->name, $contingencies[0]['name']);
        $this->assertStringContainsString('hace', $contingencies[0]['since']);
    }

    public function test_devices_not_synced(): void
    {
        $loc = Location::factory()->create();
        $synced = Device::factory()->create(['location_id' => $loc->id, 'is_enabled' => true, 'last_sync_at' => now()->subHours(1)]);
        $notSynced = Device::factory()->create(['location_id' => $loc->id, 'is_enabled' => true, 'last_sync_at' => now()->subHours(6)]);
        $disabled = Device::factory()->create(['location_id' => $loc->id, 'is_enabled' => false, 'last_sync_at' => now()->subHours(10)]);

        $service = new DashboardService;
        $noSync = $service->getDevicesNotSyncedSince(4);

        $this->assertCount(1, $noSync);
        $this->assertSame($notSynced->name, $noSync[0]['name']);
    }

    public function test_licenses_expiring_within(): void
    {
        $active = License::factory()->create(['status' => 'ACTIVE', 'valid_to' => now()->addDays(3)]);
        $expired = License::factory()->create(['status' => 'ACTIVE', 'valid_to' => now()->subDays(1)]);
        $future = License::factory()->create(['status' => 'ACTIVE', 'valid_to' => now()->addDays(15)]);

        $service = new DashboardService;
        $expiring = $service->getLicensesExpiringWithin(7);

        $this->assertCount(1, $expiring);
        $this->assertStringContainsString('en', $expiring[0]['expires_in']);
    }

    public function test_sync_failures_last_24h(): void
    {
        SyncLog::factory()->create(['status' => 'FAILED', 'started_at' => now()->subHours(2)]);
        SyncLog::factory()->create(['status' => 'FAILED', 'started_at' => now()->subHours(5)]);
        SyncLog::factory()->create(['status' => 'SUCCESS', 'started_at' => now()->subHour()]);
        SyncLog::factory()->create(['status' => 'FAILED', 'started_at' => now()->subDays(2)]);

        $failures = (new DashboardService)->getSyncFailuresLast24h();

        $this->assertSame(['count' => 2, 'total' => 3], $failures);
    }

    public function test_kpis_ignore_sales_outside_the_periods(): void
    {
        $this->travelTo(now()->setTime(12, 0));

        Transaction::factory()->create(['status' => 'PAID', 'total' => 10, 'occurred_at' => now()->startOfDay()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 20, 'occurred_at' => now()->startOfDay()->subSecond()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 40, 'occurred_at' => now()->subDays(29)->startOfDay()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 1000, 'occurred_at' => now()->subDays(60)]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 5000, 'occurred_at' => now()->addDays(2)]);
        Transaction::factory()->create(['status' => 'VOIDED', 'total' => 300, 'occurred_at' => now()]);

        $kpis = (new DashboardService)->getKpis();

        $this->assertSame(10.0, $kpis['salesToday']);
        $this->assertSame(1, $kpis['txToday']);
        $this->assertSame(20.0, $kpis['salesYesterday']);
        $this->assertSame(30.0, $kpis['sales7d']);
        $this->assertSame(70.0, $kpis['sales30d']);
    }

    public function test_week_comparison_uses_calendar_weeks(): void
    {
        // Miércoles: la semana en curso empieza el lunes
        $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0));

        Transaction::factory()->create(['status' => 'PAID', 'total' => 100, 'occurred_at' => now()->startOfWeek()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 50, 'occurred_at' => now()->startOfWeek()->subSecond()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 25, 'occurred_at' => now()->startOfWeek()->subWeek()]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 999, 'occurred_at' => now()->startOfWeek()->subWeek()->subSecond()]);

        $kpis = (new DashboardService)->getKpis();

        $this->assertSame(100.0, $kpis['salesThisWeek']);
        $this->assertSame(75.0, $kpis['salesLastWeek']);
        $this->assertSame(33.3, $kpis['weekDeltaPct']);
    }

    public function test_recent_activity_merges_sync_and_api_logs(): void
    {
        $location = Location::factory()->create(['name' => 'Sucursal Norte']);
        $device = Device::factory()->create(['location_id' => $location->id, 'name' => 'Caja 1']);

        SyncLog::factory()->create([
            'location_id' => $location->id,
            'device_id' => $device->id,
            'operation' => 'PUSH',
            'status' => 'FAILED',
            'started_at' => now()->subMinutes(10),
        ]);
        ApiRequestLog::create([
            'location_id' => $location->id,
            'device_id' => $device->id,
            'method' => 'GET',
            'path' => 'api/sync/pull',
            'response_status' => 200,
        ]);

        $activity = (new DashboardService)->getRecentActivity(8);

        $this->assertCount(2, $activity);
        // Más reciente primero: la llamada al API se creó ahora
        $this->assertSame(['Sucursal Norte', 'Caja 1', 'Pull', 'sky'], [
            $activity[0]['location'], $activity[0]['device'], $activity[0]['direction'], $activity[0]['tone'],
        ]);
        $this->assertSame(['Push', 'rose'], [$activity[1]['direction'], $activity[1]['tone']]);
    }

    public function test_voided_transactions(): void
    {
        $today = now()->toDateString();

        Transaction::factory()->create(['status' => 'VOIDED', 'total' => 50, 'occurred_at' => now()]);
        Transaction::factory()->create(['status' => 'VOIDED', 'total' => 30, 'occurred_at' => now()]);
        Transaction::factory()->create(['status' => 'VOIDED', 'total' => 100, 'occurred_at' => now()->subDays(3)]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 200, 'occurred_at' => now()]);

        $service = new DashboardService;
        $voided = $service->getVoidedTransactions();

        $this->assertSame(2, $voided['today']['count']);
        $this->assertSame(80.0, $voided['today']['total']);
        $this->assertSame(3, $voided['week']['count']);
        $this->assertSame(180.0, $voided['week']['total']);
    }

    public function test_top_products_share_over_period_total(): void
    {
        $loc = Location::factory()->create();
        $tx = Transaction::factory()->create(['status' => 'PAID', 'location_id' => $loc->id, 'occurred_at' => now()->subDays(5)]);

        // Crear líneas: total 1000
        $amounts = [600, 100, 100, 100, 100];
        foreach ($amounts as $amount) {
            $product = Product::factory()->create();
            TransactionItem::factory()->create([
                'transaction_id' => $tx->id,
                'product_id' => $product->id,
                'qty' => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ]);
        }

        $service = new DashboardService;
        $products = $service->getTopProductsBySales(30, 5);

        $this->assertCount(5, $products);
        $this->assertSame(60.0, $products[0]['pct']); // 600/1000
        $this->assertSame(10.0, $products[1]['pct']); // 100/1000
    }

    public function test_daily_sales_trend_fills_gaps(): void
    {
        $from = now()->subDays(3)->startOfDay();
        $to = now()->endOfDay();

        // Crear ventas solo en día 1 y 3
        Transaction::factory()->create(['status' => 'PAID', 'total' => 100, 'occurred_at' => $from]);
        Transaction::factory()->create(['status' => 'PAID', 'total' => 200, 'occurred_at' => $from->copy()->addDays(2)]);

        $service = new DashboardService;
        $trend = $service->getDailySalesTrend($from, $to);

        // Debe tener 4 entradas (4 días)
        $this->assertCount(4, $trend['labels']);
        $this->assertCount(4, $trend['sales']);
        $this->assertSame(100.0, $trend['sales'][0]);
        $this->assertSame(0.0, $trend['sales'][1]);
        $this->assertSame(200.0, $trend['sales'][2]);
        $this->assertSame(0.0, $trend['sales'][3]);
    }
}
