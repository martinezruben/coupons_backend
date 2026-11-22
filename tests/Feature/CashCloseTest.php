<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CashClosing;
use App\Models\Location;
use App\Models\User;
use App\Services\CashClose;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CashCloseTest extends TestCase
{
    use RefreshDatabase;

    private Location $loc;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 18:00:00');
        $this->loc = Location::factory()->create(['is_active' => true, 'name' => 'Zona 1']);
        $this->ana = User::factory()->create(['username' => 'ana']);
        $this->luis = User::factory()->create(['username' => 'luis']);
    }

    private function tx(string $kind, string $amount, User $user, string $at, ?int $dispenser = null, ?int $reversedOf = null, ?Location $loc = null): int
    {
        return DB::table('coupon_transactions')->insertGetId([
            'coupon_id' => 1, 'coupon_number' => 'C1', 'transaction_amount' => $amount, 'coupon_type' => 'QRCODE', 'kind' => $kind,
            'user_id' => $user->id, 'location_id' => ($loc ?? $this->loc)->id, 'dispenser' => $dispenser, 'reversed_of' => $reversedOf, 'created_at' => $at, 'updated_at' => $at,
        ], 'transaction_id');
    }

    private function seedShift(): void
    {
        $this->tx('REDEEM', '30.00', $this->ana, '2026-11-20 08:10:00', 1);
        $sale = $this->tx('REDEEM', '20.00', $this->ana, '2026-11-20 09:00:00', 2);
        $this->tx('REDEEM', '50.00', $this->luis, '2026-11-20 10:00:00', 1);
        $this->tx('TOPUP', '15.00', $this->luis, '2026-11-20 11:00:00');
        // La reversa la hace luis, pero es de un cobro de ana: le resta a ana
        $this->tx('REVERSAL', '-20.00', $this->luis, '2026-11-20 12:00:00', 2, $sale);
        // Otra localidad y otro día: no cuentan
        $this->tx('REDEEM', '999.00', $this->ana, '2026-11-20 09:00:00', null, null, Location::factory()->create());
        $this->tx('REDEEM', '888.00', $this->ana, '2026-11-19 09:00:00');
    }

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    public function test_the_shift_is_summarised_by_cashier_and_dispenser_with_reversals_credited_to_the_seller(): void
    {
        $this->seedShift();
        $s = CashClose::summarize($this->loc->id, Carbon::parse('2026-11-20 00:00'), Carbon::parse('2026-11-20 17:00'));

        $this->assertSame(['redemptions' => 3, 'redeemed' => 100.0, 'reversed' => 20.0, 'net' => 80.0, 'topups' => 15.0], array_intersect_key($s['totals'], array_flip(['redemptions', 'redeemed', 'reversed', 'net', 'topups'])));
        $by = collect($s['cashiers'])->keyBy('name');
        $this->assertSame(30.0, $by['ana']['net']);       // 50 cobrado − 20 reversado
        $this->assertSame(20.0, $by['ana']['reversed']);
        $this->assertSame(50.0, $by['luis']['net']);
        $this->assertSame(15.0, $by['luis']['topups']);
        $byD = collect($s['dispensers'])->keyBy('dispenser');
        $this->assertSame(80.0, $byD[1]['net']);
        $this->assertSame(0.0, $byD[2]['net']);           // 20 cobrado − 20 reversado (la reversa conserva el surtidor)
    }

    public function test_closing_stores_an_immutable_snapshot_and_blocks_overlaps_and_the_future(): void
    {
        $this->seedShift();
        $admin = $this->admin('coupon_cash_close.view', 'coupon_cash_close.close', 'coupon_cash_close.export');
        $this->actingAs($admin, 'admin');
        $post = fn (array $o = []) => $this->post(route('admin.cash-close.close'), array_merge(['location_id' => $this->loc->id, 'from' => '2026-11-20T00:00', 'to' => '2026-11-20T17:00', 'note' => 'Turno mañana'], $o));

        $post()->assertRedirect();
        $closing = CashClosing::firstOrFail();
        $this->assertSame('80.00', number_format((float) $closing->net, 2, '.', ''));
        $this->assertSame('Turno mañana', $closing->note);
        $this->assertSame($admin->id, $closing->closed_by_admin_id);
        $this->assertCount(2, $closing->details['cashiers']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'cash_close', 'entity_id' => (string) $this->loc->id]);

        // Un movimiento posterior no cambia el cierre ya hecho
        $this->tx('REDEEM', '10.00', $this->ana, '2026-11-20 13:00:00');
        $this->assertSame('80.00', number_format((float) $closing->fresh()->net, 2, '.', ''));

        $post(['from' => '2026-11-20T16:00', 'to' => '2026-11-20T17:30'])->assertSessionHasErrors('close');  // se cruza con el anterior
        $post(['from' => '2026-11-20T17:00', 'to' => '2026-11-21T09:00'])->assertSessionHasErrors('close');  // termina en el futuro
        $this->assertSame(1, CashClosing::count());

        // El siguiente turno empieza donde terminó el anterior
        $this->get(route('admin.cash-close.index', ['location_id' => $this->loc->id]))->assertOk()->assertSee('2026-11-20T17:00', false);
        $this->get(route('admin.cash-close.show', $closing))->assertOk()->assertSee('cierre #'.$closing->id);
    }

    public function test_csv_export_and_permissions(): void
    {
        $this->seedShift();
        $closing = CashClose::close($this->loc, Carbon::parse('2026-11-20 00:00'), Carbon::parse('2026-11-20 17:00'), null, $this->admin());

        $viewer = $this->admin('coupon_cash_close.view');
        $this->actingAs($viewer, 'admin');
        $this->get(route('admin.cash-close.index', ['location_id' => $this->loc->id]))->assertOk()->assertDontSee('Cerrar caja de este turno');
        $this->post(route('admin.cash-close.close'), ['location_id' => $this->loc->id, 'from' => '2026-11-20T17:00', 'to' => '2026-11-20T17:30'])->assertForbidden();
        $this->get(route('admin.cash-close.show', [$closing, 'export' => 'csv']))->assertForbidden();

        $this->actingAs($this->admin('coupon_cash_close.view', 'coupon_cash_close.export'), 'admin');
        $csv = $this->get(route('admin.cash-close.show', [$closing, 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('ana', $csv);
        $this->assertStringContainsString('TOTAL,3,100.00,20.00,80.00,15.00', $csv);
        $this->assertStringContainsString('Surtidor 1', $csv);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.cash-close.index'))->assertForbidden();
    }
}
