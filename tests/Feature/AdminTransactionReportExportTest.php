<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Location;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTransactionReportExportTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'transactions_report.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'report-viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo('transactions_report.view');
        $this->admin = AdminUser::factory()->create();
        $this->admin->assignRole($role);
    }

    private function seedTransactions(): array
    {
        $locA = Location::create(['id' => (string) \Str::uuid(), 'name' => 'Sede A', 'is_active' => true]);
        $locB = Location::create(['id' => (string) \Str::uuid(), 'name' => 'Sede B', 'is_active' => true]);
        $user = User::factory()->create();
        $device = Device::create([
            'id' => (string) \Str::uuid(),
            'location_id' => $locA->id,
            'device_fingerprint' => 'fp-report-1',
            'is_enabled' => true,
        ]);

        $txA = Transaction::create([
            'id' => (string) \Str::uuid(),
            'external_id' => 'EXT-1',
            'location_id' => $locA->id,
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'PAID',
            'total' => 150,
            'occurred_at' => '2026-05-10 12:00:00',
            'turn_number' => 1,
            'is_synced' => true,
        ]);
        TransactionItem::create([
            'transaction_id' => $txA->id,
            'product_name' => 'Café',
            'product_sku' => 'SKU-CAFE',
            'qty' => 2,
            'unit_price' => 50,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 100,
        ]);
        TransactionItem::create([
            'transaction_id' => $txA->id,
            'product_name' => 'Té',
            'product_sku' => 'SKU-TE',
            'qty' => 1,
            'unit_price' => 50,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 50,
        ]);

        $txB = Transaction::create([
            'id' => (string) \Str::uuid(),
            'external_id' => 'EXT-2',
            'location_id' => $locB->id,
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'PAID',
            'total' => 200,
            'occurred_at' => '2026-06-15 18:00:00',
            'turn_number' => 2,
            'is_synced' => true,
        ]);
        TransactionItem::create([
            'transaction_id' => $txB->id,
            'product_name' => 'Sandwich',
            'product_sku' => 'SKU-SAND',
            'qty' => 1,
            'unit_price' => 200,
            'discount' => 0,
            'tax' => 0,
            'line_total' => 200,
        ]);

        return [$txA, $txB, $locA];
    }

    private function rows(string $payload): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'txreport').'.xlsx';
        file_put_contents($tmp, $payload);
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        $out = [];
        foreach ($sheet->toArray() as $row) {
            $out[] = $row;
        }
        unlink($tmp);

        return $out;
    }

    public function test_report_without_detail_has_one_row_per_transaction(): void
    {
        $this->seedTransactions();

        $res = $this->actingAs($this->admin, 'admin')
            ->postJson(route('admin.transactions.report.export'), ['include_detail' => false]);

        $res->assertOk();
        $rows = $this->rows($res->streamedContent());
        // fila 0 = cabeceras; EXT-1 y EXT-2 una fila cada una
        $this->assertCount(3, $rows);
        $this->assertEquals('EXT-1', $rows[1][1]);
        $this->assertEquals('EXT-2', $rows[2][1]);
    }

    public function test_report_with_detail_has_one_row_per_item(): void
    {
        $this->seedTransactions();

        $res = $this->actingAs($this->admin, 'admin')
            ->postJson(route('admin.transactions.report.export'), ['include_detail' => true]);

        $res->assertOk();
        $rows = $this->rows($res->streamedContent());
        // cabeceras + 2 líneas (EXT-1) + 1 línea (EXT-2)
        $this->assertCount(4, $rows);
        $this->assertEquals('SKU-CAFE', $rows[1][10]);
        $this->assertEquals('Café', $rows[1][11]);
        $this->assertEquals('SKU-TE', $rows[2][10]);
        $this->assertEquals('SKU-SAND', $rows[3][10]);
    }

    public function test_report_filters_by_date_and_location(): void
    {
        [$txA, $txB, $locA] = $this->seedTransactions();

        // solo fecha: rango que excluye EXT-2
        $res = $this->actingAs($this->admin, 'admin')
            ->postJson(route('admin.transactions.report.export'), [
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
                'include_detail' => false,
            ]);
        $rows = $this->rows($res->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertEquals('EXT-1', $rows[1][1]);

        // solo localidad A
        $res = $this->actingAs($this->admin, 'admin')
            ->postJson(route('admin.transactions.report.export'), [
                'location_id' => $locA->id,
                'include_detail' => false,
            ]);
        $rows = $this->rows($res->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertEquals('EXT-1', $rows[1][1]);
    }

    public function test_report_rejects_from_after_to(): void
    {
        $this->seedTransactions();

        $this->actingAs($this->admin, 'admin')
            ->postJson(route('admin.transactions.report.export'), [
                'date_from' => '2026-06-01',
                'date_to' => '2026-05-01',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'La fecha inicial no puede ser posterior a la final.']);
    }

    public function test_report_screen_lists_transactions_with_date_filters(): void
    {
        $this->seedTransactions();

        $res = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.screens.index', 'transactions-report'));

        $res->assertOk()
            ->assertSee('EXT-1')
            ->assertSee('EXT-2')
            ->assertSee('Descargar');

        // filtro por fecha: solo EXT-1
        $res = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.screens.index', 'transactions-report').'?'.http_build_query([
                'filter' => ['date_from' => '2026-05-01', 'date_to' => '2026-05-31'],
            ]));
        $res->assertOk()->assertSee('EXT-1')->assertDontSee('EXT-2');
    }

    public function test_guest_cannot_export_report(): void
    {
        $this->postJson(route('admin.transactions.report.export'), [])
            ->assertUnauthorized();
    }
}
