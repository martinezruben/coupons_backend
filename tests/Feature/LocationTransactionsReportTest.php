<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use App\Models\User;
use App\Services\Shifts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocationTransactionsReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $a;

    private Location $b;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 18:00:00');
        $this->a = Location::factory()->create(['name' => 'Zona Alfa', 'is_active' => true]);
        $this->b = Location::factory()->create(['name' => 'Zona Beta', 'is_active' => true]);
        $this->ana = User::factory()->create(['username' => 'ana']);
        $this->luis = User::factory()->create(['username' => 'luis']);
    }

    private function tx(Location $loc, string $kind, string $amount, User $u, string $number, string $at, array $extra = []): int
    {
        return DB::table('coupon_transactions')->insertGetId($extra + [
            'coupon_id' => 1, 'coupon_number' => $number, 'transaction_amount' => $amount, 'coupon_type' => 'QRCODE', 'kind' => $kind, 'user_id' => $u->id,
            'location_id' => $loc->id, 'created_at' => $at, 'updated_at' => $at,
        ], 'transaction_id');
    }

    private function admin(array $locations = [], string ...$permissions): AdminUser
    {
        $a = AdminUser::factory()->create(['location_scope' => $locations === [] ? 'all' : 'selected']);
        $a->locations()->sync(collect($locations)->pluck('id')->all());
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $a->givePermissionTo($permissions);

        return $a;
    }

    private function seedData(): array
    {
        $sold = $this->tx($this->a, 'REDEEM', '30.00', $this->ana, 'QAAA0001', '2026-11-20 09:00:00', ['dispenser' => 1, 'sale_ref' => 'sale-aaaa-1111', 'balance_after' => '70.00']);
        $rev = $this->tx($this->a, 'REDEEM', '20.00', $this->ana, 'QAAA0002', '2026-11-20 10:00:00', ['dispenser' => 2]);
        $reversal = $this->tx($this->a, 'REVERSAL', '-20.00', $this->luis, 'QAAA0002', '2026-11-20 11:00:00', ['dispenser' => 2, 'reversed_of' => $rev]);
        $topup = $this->tx($this->a, 'TOPUP', '15.00', $this->luis, 'QAAA0003', '2026-11-20 12:00:00');
        $bar = $this->tx($this->a, 'REDEEM', '45.00', $this->luis, '123456789012', '2026-11-20 13:00:00', ['coupon_type' => 'CODBAR']);
        $old = $this->tx($this->a, 'REDEEM', '77.00', $this->ana, 'QAAA0099', '2026-11-18 09:00:00', ['dispenser' => 1]);
        $other = $this->tx($this->b, 'REDEEM', '999.00', $this->ana, 'QBBB0001', '2026-11-20 09:30:00');

        return compact('sold', 'rev', 'reversal', 'topup', 'bar', 'old', 'other');
    }

    private function page(array $q = [])
    {
        $html = $this->get(route('admin.reports.location-transactions', $q))->assertOk()->getContent();

        return substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
    }

    public function test_it_lists_what_was_consumed_in_the_selected_location_for_today_by_default_with_totals(): void
    {
        $this->seedData();
        $this->actingAs($this->admin([], 'coupon_location_transactions.view'), 'admin');
        $this->post(route('admin.location.switch'), ['location_id' => $this->a->id]);

        $main = $this->page();
        foreach (['QAAA0001', 'QAAA0002', 'QAAA0003', '8901 2'] as $t) {
            // El código de barras sale enmascarado: solo los últimos 4 dígitos
            if ($t === '8901 2') {
                $this->assertStringContainsString('********9012', $main);
                $this->assertStringNotContainsString('123456789012', $main);

                continue;
            }
            $this->assertStringContainsString($t, $main);
        }
        $this->assertStringNotContainsString('QAAA0099', $main);   // otro día
        $this->assertStringNotContainsString('QBBB0001', $main);   // otra localidad
        // Totales de hoy en Alfa: cobrado 95, reversado 20, neto 75, recargado 15, 6 movimientos
        foreach (['$95.00', '$20.00', '$75.00', '$15.00'] as $t) {
            $this->assertStringContainsString($t, $main);
        }
        $this->assertStringContainsString('>5<', preg_replace('/\s+/', '', $main));  // 5 movimientos
        $this->assertStringNotContainsString('Localidad</th>', $main);  // con una sola localidad no se repite la columna
    }

    public function test_the_filters_narrow_by_status_cashier_dispenser_amount_type_period_and_shift(): void
    {
        $ids = $this->seedData();
        $this->actingAs($this->admin([], 'coupon_location_transactions.view'), 'admin');
        $this->post(route('admin.location.switch'), ['location_id' => $this->a->id]);

        $sold = $this->page(['status' => 'sold']);
        $this->assertStringContainsString('QAAA0001', $sold);
        $this->assertStringNotContainsString('QAAA0003', $sold);
        $reversed = $this->page(['status' => 'reversed']);
        $this->assertStringContainsString('Cobro revertido', $reversed);
        $this->assertStringContainsString('QAAA0002', $reversed);
        $this->assertStringNotContainsString('QAAA0001', $reversed);
        $this->assertStringContainsString('QAAA0003', $this->page(['status' => 'topup']));
        $this->assertStringContainsString('Reversa', $this->page(['status' => 'reversal']));

        $ana = $this->page(['user_id' => $this->ana->id]);
        $this->assertStringContainsString('QAAA0001', $ana);
        $this->assertStringNotContainsString('QAAA0003', $ana);
        $d2 = $this->page(['dispenser' => 2]);
        $this->assertStringContainsString('QAAA0002', $d2);
        $this->assertStringNotContainsString('QAAA0001', $d2);
        $none = $this->page(['dispenser' => 'none']);
        $this->assertStringContainsString('QAAA0003', $none);
        $this->assertStringNotContainsString('QAAA0001', $none);
        $big = $this->page(['min' => 40]);
        $this->assertStringContainsString('********9012', $big);
        $this->assertStringNotContainsString('QAAA0001', $big);
        $small = $this->page(['max' => 25]);
        $this->assertStringContainsString('QAAA0002', $small);
        $this->assertStringNotContainsString('123456789012', $small);
        $this->assertStringNotContainsString('********9012', $small);
        $this->assertStringContainsString('********9012', $this->page(['coupon_type' => 'CODBAR']));
        $this->assertStringNotContainsString('QAAA0001', $this->page(['coupon_type' => 'CODBAR']));

        // Periodos: «7d» incluye el movimiento del día 18; «yesterday» no tiene nada
        $this->assertStringContainsString('QAAA0099', $this->page(['period' => '7d']));
        $this->assertStringContainsString('No hay movimientos', $this->page(['period' => 'yesterday']));
        $custom = $this->page(['period' => 'custom', 'from' => '2026-11-18T00:00', 'to' => '2026-11-18T23:59']);
        $this->assertStringContainsString('QAAA0099', $custom);
        $this->assertStringNotContainsString('QAAA0001', $custom);

        // Turno: el cierre a las 11:00 deja los dos primeros cobros en el turno 1
        $shift = Shifts::current($this->a);
        DB::table('coupon_transactions')->where('location_id', $this->a->id)->update(['shift_id' => $shift->id]);
        $this->assertStringContainsString('Turno '.$shift->number, $this->page(['shift_id' => $shift->id, 'period' => '7d']));
        $this->assertStringContainsString('No hay movimientos', $this->page(['shift_id' => $shift->id + 99]));
        $this->assertSame(7, count($ids));
    }

    public function test_the_search_box_finds_by_coupon_transaction_sale_or_cashier(): void
    {
        $ids = $this->seedData();
        $this->actingAs($this->admin([], 'coupon_location_transactions.view'), 'admin');
        $this->post(route('admin.location.switch'), ['location_id' => $this->a->id]);

        $byCoupon = $this->page(['q' => 'AAA0003']);
        $this->assertStringContainsString('QAAA0003', $byCoupon);
        $this->assertStringNotContainsString('QAAA0001', $byCoupon);
        $this->assertStringContainsString('QAAA0001', $this->page(['q' => 'sale-aaaa']));
        $this->assertStringContainsString('QAAA0001', $this->page(['q' => (string) $ids['sold']]));
        $luis = $this->page(['q' => 'luis']);
        $this->assertStringContainsString('QAAA0003', $luis);
        $this->assertStringNotContainsString('QAAA0001', $luis);
        $this->assertStringContainsString('No hay movimientos', $this->page(['q' => 'no-existe']));
    }

    public function test_it_is_paginated_and_sortable(): void
    {
        foreach (range(1, 30) as $i) {
            $this->tx($this->a, 'REDEEM', (string) $i, $this->ana, sprintf('QPAG%04d', $i), '2026-11-20 08:'.sprintf('%02d', $i).':00');
        }
        $this->actingAs($this->admin([], 'coupon_location_transactions.view'), 'admin');
        $this->post(route('admin.location.switch'), ['location_id' => $this->a->id]);

        $p1 = $this->page();
        $this->assertStringContainsString('QPAG0030', $p1);   // más recientes primero
        $this->assertStringNotContainsString('QPAG0001', $p1);
        $p2 = $this->page(['page' => 2]);
        $this->assertStringContainsString('QPAG0001', $p2);
        $this->assertStringContainsString('QPAG0001', $this->page(['per_page' => 100]));
        $asc = $this->page(['sort' => 'transaction_amount', 'dir' => 'asc']);
        $this->assertLessThan(strpos($asc, 'QPAG0002'), strpos($asc, 'QPAG0001'));
    }

    public function test_the_export_downloads_everything_filtered_masks_barcodes_and_is_audited(): void
    {
        $this->seedData();
        $this->tx($this->a, 'REDEEM', '1.00', $this->ana, '=CMD()', '2026-11-20 14:00:00');
        $admin = $this->admin([], 'coupon_location_transactions.view', 'coupon_location_transactions.export');
        $this->actingAs($admin, 'admin');
        $this->post(route('admin.location.switch'), ['location_id' => $this->a->id]);

        $csv = $this->get(route('admin.reports.location-transactions', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Fecha,Hora,Transacción,Tipo,Estado,Cupón', $csv);
        $this->assertStringContainsString('QAAA0001', $csv);
        $this->assertStringContainsString('Cobro revertido', $csv);
        $this->assertStringContainsString('Reversa', $csv);
        $this->assertStringContainsString('Recarga', $csv);
        $this->assertStringContainsString('********9012', $csv);
        $this->assertStringNotContainsString('123456789012', $csv);
        $this->assertStringNotContainsString('QBBB0001', $csv);         // otra localidad
        $this->assertStringNotContainsString('QAAA0099', $csv);         // fuera del periodo
        $this->assertStringContainsString("'=CMD()", $csv);              // celdas que parecen fórmulas, neutralizadas
        $this->assertStringContainsString('sale-aaaa-1111', $csv);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'export', 'entity_type' => 'location_transactions']);

        // Respeta los filtros
        $only = $this->get(route('admin.reports.location-transactions', ['export' => 'csv', 'status' => 'topup']))->streamedContent();
        $this->assertStringContainsString('QAAA0003', $only);
        $this->assertStringNotContainsString('QAAA0001', $only);
    }

    public function test_permissions_and_location_scope_apply(): void
    {
        $this->seedData();
        $viewer = $this->admin([$this->a], 'coupon_location_transactions.view');
        $this->actingAs($viewer, 'admin');

        $main = $this->page();
        $this->assertStringContainsString('QAAA0001', $main);
        $this->assertStringNotContainsString('QBBB0001', $main);   // su permiso no alcanza a Beta
        $this->get(route('admin.reports.location-transactions', ['export' => 'csv']))->assertForbidden();   // sin permiso de exportar
        $this->actingAs($this->admin(), 'admin')->get(route('admin.reports.location-transactions'))->assertForbidden();

        // Con barras completas solo con el permiso de exportar números completos
        $full = $this->admin([$this->a], 'coupon_location_transactions.view', 'coupon_barcodes.export_full');
        $this->actingAs($full, 'admin');
        $this->assertStringContainsString('123456789012', $this->page());
    }
}
