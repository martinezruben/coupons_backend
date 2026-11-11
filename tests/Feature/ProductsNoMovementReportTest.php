<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductsNoMovementReportTest extends TestCase
{
    use RefreshDatabase;

    private function sell(Product $product, Location $location, string $when, string $status = 'PAID'): void
    {
        $tx = Transaction::factory()->create([
            'location_id' => $location->id,
            'status' => $status,
            'occurred_at' => Carbon::parse($when),
        ]);
        TransactionItem::factory()->create(['transaction_id' => $tx->id, 'product_id' => $product->id]);
    }

    private function admin(): AdminUser
    {
        Permission::findOrCreate('products_no_movement.view', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('products_no_movement.view');

        return $admin;
    }

    public function test_lists_active_products_without_paid_sales_in_the_period(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $loc = Location::factory()->create();
        $created = Carbon::parse('2026-01-01');

        $sold = Product::factory()->create(['name' => 'Vendido', 'created_at' => $created]);
        $old = Product::factory()->create(['name' => 'Vendido hace meses', 'created_at' => $created]);
        $never = Product::factory()->create(['name' => 'Nunca', 'created_at' => $created]);
        $voidedOnly = Product::factory()->create(['name' => 'Solo anulada', 'created_at' => $created]);
        $inactive = Product::factory()->create(['name' => 'Inactivo', 'is_active' => false, 'created_at' => $created]);
        $tooNew = Product::factory()->create(['name' => 'Creado despues', 'created_at' => Carbon::parse('2026-10-20')]);

        $this->sell($sold, $loc, '2026-10-05 10:00:00');
        $this->sell($old, $loc, '2026-06-01 10:00:00');
        $this->sell($voidedOnly, $loc, '2026-10-05 10:00:00', 'VOIDED');

        $rows = (new ReportService)->getProductsWithoutMovement(Carbon::parse('2026-09-14'), Carbon::parse('2026-10-14')->endOfDay());
        $names = $rows->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Vendido hace meses', 'Nunca', 'Solo anulada'], $names);
        $this->assertNotContains('Inactivo', $names);
        $this->assertNotContains('Creado despues', $names);

        // Los nunca vendidos van primero; los demás por la venta más antigua
        $this->assertNull($rows->first()->last_sold_at);
        $this->assertSame('Vendido hace meses', $rows->last()->name);
        $this->assertStringStartsWith('2026-06-01', (string) $rows->last()->last_sold_at);
    }

    public function test_location_filter_only_counts_sales_of_that_location(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $a = Location::factory()->create();
        $b = Location::factory()->create();
        $product = Product::factory()->create(['name' => 'Solo en B', 'created_at' => Carbon::parse('2026-01-01')]);
        $this->sell($product, $b, '2026-10-05 10:00:00');

        $from = Carbon::parse('2026-09-14');
        $to = Carbon::parse('2026-10-14')->endOfDay();

        $this->assertCount(0, (new ReportService)->getProductsWithoutMovement($from, $to));
        $this->assertCount(0, (new ReportService)->getProductsWithoutMovement($from, $to, $b->id));
        $this->assertSame(['Solo en B'], (new ReportService)->getProductsWithoutMovement($from, $to, $a->id)->pluck('name')->all());
    }

    public function test_page_and_exports_require_permission_and_work(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        Product::factory()->create(['name' => 'Producto parado', 'created_at' => Carbon::parse('2026-01-01')]);

        $plain = AdminUser::factory()->create();
        $this->actingAs($plain, 'admin')->get('/admin/reports/products-no-movement')->assertForbidden();
        $this->actingAs($plain, 'admin')
            ->post('/admin/reports/products-no-movement/export-csv', ['date_from' => '2026-09-01', 'date_to' => '2026-10-14'])
            ->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')
            ->get('/admin/reports/products-no-movement')
            ->assertOk()
            ->assertSee('Producto parado')
            ->assertSee('Nunca vendido');

        $csv = $this->actingAs($admin, 'admin')
            ->post('/admin/reports/products-no-movement/export-csv', ['date_from' => '2026-09-01', 'date_to' => '2026-10-14'])
            ->assertOk();
        $this->assertStringContainsString('Producto parado', $csv->streamedContent());

        $this->actingAs($admin, 'admin')
            ->post('/admin/reports/products-no-movement/export', ['date_from' => '2026-09-01', 'date_to' => '2026-10-14'])
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($admin, 'admin')
            ->post('/admin/reports/products-no-movement/export-csv', ['date_from' => '2026-10-14', 'date_to' => '2026-09-01'])
            ->assertStatus(422);
    }
}
