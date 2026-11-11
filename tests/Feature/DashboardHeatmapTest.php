<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Transaction;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardHeatmapTest extends TestCase
{
    use RefreshDatabase;

    private function sale(Location $location, string $when, float $total, string $status = 'PAID'): void
    {
        Transaction::factory()->create([
            'location_id' => $location->id,
            'status' => $status,
            'total' => $total,
            'occurred_at' => Carbon::parse($when),
        ]);
    }

    public function test_heatmap_groups_by_weekday_and_hour_monday_first(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00')); // miércoles
        $location = Location::factory()->create();

        $this->sale($location, '2026-10-12 23:30:00', 100); // lunes 23h
        $this->sale($location, '2026-10-13 00:10:00', 40);  // martes 00h
        $this->sale($location, '2026-10-13 00:50:00', 10);  // martes 00h
        $this->sale($location, '2026-10-11 09:00:00', 70);  // domingo 09h

        $map = (new DashboardService)->getSalesHeatmap(30);

        $this->assertSame(['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'], $map['days']);
        $this->assertCount(24, $map['hours']);
        $this->assertEquals(100, $map['sales'][0][23]);   // lunes 23h
        $this->assertEquals(50, $map['sales'][1][0]);     // martes 00h (40 + 10)
        $this->assertSame(2, $map['tickets'][1][0]);
        $this->assertEquals(70, $map['sales'][6][9]);     // domingo 09h
        $this->assertEquals(0, $map['sales'][2][12]);
    }

    public function test_heatmap_ignores_voided_old_sales_and_filters_by_location(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $a = Location::factory()->create();
        $b = Location::factory()->create();

        $this->sale($a, '2026-10-12 10:00:00', 100);
        $this->sale($a, '2026-10-12 10:30:00', 999, 'VOIDED');
        $this->sale($a, '2026-08-01 10:00:00', 500); // fuera de los 30 días
        $this->sale($b, '2026-10-12 10:15:00', 7);

        $all = (new DashboardService)->getSalesHeatmap(30);
        $this->assertEquals(107, $all['sales'][0][10]);

        $onlyA = (new DashboardService($a->id))->getSalesHeatmap(30);
        $this->assertEquals(100, $onlyA['sales'][0][10]);

        // La venta del sábado 1 de agosto solo entra en el periodo de 90 días
        $this->assertEquals(0, $all['sales'][5][10]);
        $this->assertEquals(500, (new DashboardService)->getSalesHeatmap(90)['sales'][5][10]);
    }
}
