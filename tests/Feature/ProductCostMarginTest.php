<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductCostMarginTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Device, 1: string, 2: User} */
    private function pairedDevice(): array
    {
        $location = Location::factory()->create(['is_active' => true]);
        $device = Device::factory()->create(['location_id' => $location->id, 'is_enabled' => true]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE', 'valid_from' => now()->subDay(), 'valid_to' => now()->addDay()]);
        $user = User::factory()->create();
        $user->locations()->attach($location->id);

        return [$device, $device->createToken('t')->plainTextToken, $user];
    }

    private function push(string $token, User $user, string $externalId, array $items): void
    {
        $this->postJson('/api/sync/push', ['transactions' => [[
            'external_id' => $externalId,
            'user_id' => $user->id,
            'turn_number' => 1,
            'status' => 'PAID',
            'total' => 100,
            'occurred_at' => now()->toIso8601String(),
            'items' => $items,
            'payments' => [],
        ]]], ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    private function sale(Location $loc, Product $product, float $qty, float $lineTotal, float $tax, ?float $unitCost, string $when = '2026-10-10 10:00:00', string $status = 'PAID'): void
    {
        $tx = Transaction::factory()->create(['location_id' => $loc->id, 'status' => $status, 'occurred_at' => Carbon::parse($when)]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id, 'product_id' => $product->id, 'qty' => $qty,
            'unit_price' => $lineTotal / $qty, 'unit_cost' => $unitCost, 'tax' => $tax, 'line_total' => $lineTotal,
        ]);
    }

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    public function test_push_freezes_the_product_cost_on_the_line(): void
    {
        [, $token, $user] = $this->pairedDevice();
        $withCost = Product::factory()->create(['cost' => 40]);
        $noCost = Product::factory()->create(['cost' => null]);

        $this->push($token, $user, 'ext-1', [
            ['product_id' => $withCost->id, 'product_name' => 'A', 'qty' => 2, 'unit_price' => 100, 'line_total' => 200],
            ['product_id' => $noCost->id, 'product_name' => 'B', 'qty' => 1, 'unit_price' => 50, 'line_total' => 50],
            ['product_id' => null, 'product_name' => 'Suelto', 'qty' => 1, 'unit_price' => 10, 'line_total' => 10],
        ]);

        $lines = TransactionItem::query()->orderBy('product_name')->get()->keyBy('product_name');
        $this->assertEquals(40, $lines['A']->unit_cost);
        $this->assertNull($lines['B']->unit_cost);
        $this->assertNull($lines['Suelto']->unit_cost);
    }

    public function test_a_replayed_push_keeps_the_frozen_cost_after_the_product_cost_changes(): void
    {
        [, $token, $user] = $this->pairedDevice();
        $product = Product::factory()->create(['cost' => 40]);
        $items = [['product_id' => $product->id, 'product_name' => 'A', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100]];

        $this->push($token, $user, 'ext-1', $items);
        $product->update(['cost' => 90]);
        $this->push($token, $user, 'ext-1', $items); // reenvío de la misma venta

        $this->assertEquals(40, TransactionItem::first()->unit_cost);

        // Una venta nueva sí toma el costo actual
        $this->push($token, $user, 'ext-2', $items);
        $second = TransactionItem::whereHas('transaction', fn ($q) => $q->where('external_id', 'ext-2'))->first();
        $this->assertEquals(90, $second->unit_cost);
    }

    public function test_cost_is_never_sent_to_the_pos(): void
    {
        [$device, $token] = $this->pairedDevice();
        Product::factory()->create(['cost' => 33.33]);

        $res = $this->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]), ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertStringNotContainsString('33.33', $res->getContent());
        $this->assertArrayNotHasKey('cost', $res->json('data.products.0'));
    }

    public function test_margin_uses_net_revenue_and_only_lines_with_cost(): void
    {
        $loc = Location::factory()->create();
        $a = Product::factory()->create(['name' => 'Con costo']);
        $b = Product::factory()->create(['name' => 'Sin costo']);
        $c = Product::factory()->create(['name' => 'Pierde']);

        // line_total incluye impuesto (config por defecto): neto = 118 - 18 = 100; costo = 2 x 30 = 60 -> margen 40
        $this->sale($loc, $a, 2, 118, 18, 30);
        $this->sale($loc, $b, 1, 59, 9, null);
        $this->sale($loc, $c, 1, 59, 9, 80);                       // neto 50, costo 80 -> -30
        $this->sale($loc, $a, 1, 999, 0, 1, status: 'VOIDED');    // anulada: no cuenta
        $this->sale($loc, $a, 1, 999, 0, 1, when: '2026-08-01 10:00:00'); // fuera del periodo

        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-31')->endOfDay();
        $rows = (new ReportService)->getProductsMargin($from, $to)->keyBy('name');

        $this->assertEquals(100, $rows['Con costo']['revenue']);
        $this->assertEquals(60, $rows['Con costo']['cost']);
        $this->assertEquals(40, $rows['Con costo']['margin']);
        $this->assertEquals(40.0, $rows['Con costo']['margin_pct']);
        $this->assertEquals(-30, $rows['Pierde']['margin']);
        $this->assertNull($rows['Sin costo']['margin']);
        $this->assertSame(['Con costo', 'Pierde', 'Sin costo'], (new ReportService)->getProductsMargin($from, $to)->pluck('name')->all());

        $summary = (new ReportService)->getMarginSummary($from, $to);
        $this->assertEquals(150, $summary['revenue']);  // 100 + 50
        $this->assertEquals(140, $summary['cost']);     // 60 + 80
        $this->assertEquals(10, $summary['margin']);
        // cobertura = ventas con costo / ventas totales = (118 + 59) / (118 + 59 + 59)
        $this->assertEquals(75.0, $summary['coverage_pct']);
    }

    public function test_margin_respects_the_tax_inclusion_setting_and_the_location_filter(): void
    {
        $a = Location::factory()->create();
        $b = Location::factory()->create();
        $product = Product::factory()->create();
        $this->sale($a, $product, 1, 100, 18, 40);
        $this->sale($b, $product, 1, 500, 0, 100);

        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-31')->endOfDay();

        config(['pos.line_total_includes_tax' => false]);
        $this->assertEquals(60, (new ReportService)->getMarginSummary($from, $to, $a->id)['margin']); // 100 - 40
        config(['pos.line_total_includes_tax' => true]);
        $this->assertEquals(42, (new ReportService)->getMarginSummary($from, $to, $a->id)['margin']); // (100 - 18) - 40
        $this->assertEquals(400, (new ReportService)->getMarginSummary($from, $to, $b->id)['margin']);
    }

    public function test_margin_summary_without_costs_has_no_margin(): void
    {
        $this->sale(Location::factory()->create(), Product::factory()->create(), 1, 100, 0, null);

        $summary = (new ReportService)->getMarginSummary(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay());

        $this->assertNull($summary['margin']);
        $this->assertEquals(0.0, $summary['coverage_pct']);
    }

    public function test_margin_report_page_and_exports_need_their_own_permission(): void
    {
        $product = Product::factory()->create(['name' => 'Cafe margen']);
        $this->sale(Location::factory()->create(), $product, 1, 100, 0, 30);
        $query = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'];

        $viewer = $this->admin('products_best_sellers.view', 'products.view');
        $this->actingAs($viewer, 'admin')->get('/admin/reports/products-margin')->assertForbidden();
        $this->actingAs($viewer, 'admin')->post('/admin/reports/products-margin/export-csv', $query)->assertForbidden();

        $admin = $this->admin('products_margin.view');
        $this->actingAs($admin, 'admin')->get('/admin/reports/products-margin?'.http_build_query($query))
            ->assertOk()->assertSee('Cafe margen')->assertSee('Cobertura de costos');

        $csv = $this->actingAs($admin, 'admin')->post('/admin/reports/products-margin/export-csv', $query)->assertOk();
        $this->assertStringContainsString('Cafe margen', $csv->streamedContent());
        $this->assertStringContainsString('Margen %', $csv->streamedContent());

        $this->actingAs($admin, 'admin')->post('/admin/reports/products-margin/export', $query)->assertOk();
    }

    public function test_dashboard_margin_card_is_only_for_users_with_the_margin_permission(): void
    {
        $this->sale(Location::factory()->create(), Product::factory()->create(), 1, 100, 0, 30, when: now()->subDay()->toDateTimeString());
        $label = fn (array $kpis) => collect($kpis)->contains(fn ($k) => $k['label'] === 'Margen (30 días)');

        $this->actingAs($this->admin('dashboard.view'), 'admin')->get(route('admin.dashboard'))
            ->assertOk()->assertViewHas('kpis', fn (array $k) => ! $label($k))->assertDontSee('Margen (30 días)');

        Cache::flush();
        $this->actingAs($this->admin('dashboard.view', 'products_margin.view'), 'admin')->get(route('admin.dashboard'))
            ->assertOk()->assertViewHas('kpis', fn (array $k) => $label($k))->assertSee('Margen (30 días)');
    }

    public function test_cost_can_be_edited_validated_and_is_not_searchable_by_viewers(): void
    {
        $editor = $this->admin('products.view', 'products.edit');
        $product = Product::factory()->create(['name' => 'Producto X', 'cost' => 12.5]);

        $this->actingAs($editor, 'admin')
            ->put(route('admin.screens.update', ['products', $product->id]), ['sku' => $product->sku, 'name' => 'Producto X', 'price' => 20, 'cost' => '-3', 'tax_rate' => 18])
            ->assertSessionHasErrors('cost');

        $this->actingAs($editor, 'admin')
            ->put(route('admin.screens.update', ['products', $product->id]), ['sku' => $product->sku, 'name' => 'Producto X', 'price' => 20, 'cost' => '15.75', 'tax_rate' => 18])
            ->assertSessionHasNoErrors();
        $this->assertEquals(15.75, $product->fresh()->cost);

        $this->actingAs($editor, 'admin')
            ->put(route('admin.screens.update', ['products', $product->id]), ['sku' => $product->sku, 'name' => 'Producto X', 'price' => 20, 'cost' => '', 'tax_rate' => 18])
            ->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->cost);

        // Un usuario de solo lectura no puede descubrir costos buscando el número
        $product->update(['cost' => 777.77]);
        $viewer = $this->admin('products.view');
        $this->actingAs($viewer, 'admin')->get(route('admin.screens.index', 'products').'?q=777.77')
            ->assertOk()->assertDontSee('Producto X');
    }

    public function test_excel_import_keeps_cost_without_the_column_and_sets_it_with_the_column(): void
    {
        $editor = $this->admin('products.edit');
        $product = Product::factory()->create(['sku' => 'S-1', 'name' => 'Antes', 'price' => 10, 'tax_rate' => 18, 'cost' => 4.5]);

        $import = function (array $header, array $row) use ($editor) {
            $sheet = ($book = new Spreadsheet)->getActiveSheet();
            $sheet->fromArray([$header, $row]);
            $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
            (new Xlsx($book))->save($path);

            return $this->actingAs($editor, 'admin')->post(route('admin.products.excel.import'), ['file' => new UploadedFile($path, 'p.xlsx', null, null, true)]);
        };

        $import(['product_id', 'sku', 'name', 'price', 'tax_rate'], [$product->id, 'S-1', 'Despues', 12, 18]);
        $fresh = $product->fresh();
        $this->assertSame('Despues', $fresh->name);
        $this->assertEquals(4.5, $fresh->cost);       // sin columna cost: no se toca

        $import(['product_id', 'sku', 'name', 'price', 'cost', 'tax_rate'], [$product->id, 'S-1', 'Despues', 12, 6.25, 18]);
        $this->assertEquals(6.25, $product->fresh()->cost);

        $import(['product_id', 'sku', 'name', 'price', 'cost', 'tax_rate'], [$product->id, 'S-1', 'Despues', 12, '', 18]);
        $this->assertNull($product->fresh()->cost);    // columna vacía = quitar costo

        $product->update(['cost' => 9.99]);
        $import(['product_id', 'sku', 'name', 'price', 'cost', 'tax_rate'], [$product->id, 'S-1', 'Despues', 12, 'abc', 18]);
        $this->assertEquals(9.99, $product->fresh()->cost);  // valor inválido: la fila se omite
    }
}
