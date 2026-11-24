<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shifts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private Location $loc;

    private User $ana;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 18:00:00');
        $this->loc = Location::factory()->create(['is_active' => true, 'name' => 'Zona 1', 'dispensers' => 3]);
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

    private function seedDay(): void
    {
        $this->tx('REDEEM', '30.00', $this->ana, '2026-11-20 08:10:00', 1);
        $sale = $this->tx('REDEEM', '20.00', $this->ana, '2026-11-20 09:00:00', 2);
        $this->tx('REDEEM', '50.00', $this->luis, '2026-11-20 10:00:00', 1);
        $this->tx('TOPUP', '15.00', $this->luis, '2026-11-20 11:00:00');
        $this->tx('REVERSAL', '-20.00', $this->luis, '2026-11-20 12:00:00', 2, $sale);
        $this->tx('REDEEM', '40.00', $this->ana, '2026-11-20 13:00:00', 3);   // exactamente en la hora de corte de los tests
        $this->tx('REDEEM', '60.00', $this->luis, '2026-11-20 15:30:00', 1);
    }

    public function test_every_location_gets_one_open_shift_that_starts_at_its_first_movement(): void
    {
        $this->seedDay();
        $first = Shifts::current($this->loc);

        $this->assertSame(1, $first->number);
        $this->assertSame('open', $first->status);
        $this->assertSame('2026-11-20 08:10:00', $first->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($first->id, Shifts::current($this->loc)->id); // siempre el mismo mientras esté abierto
        $this->assertSame(1, Shift::where('location_id', $this->loc->id)->count());
        // El historial que ya existía entra en el primer turno
        $this->assertSame(0, DB::table('coupon_transactions')->whereNull('shift_id')->count());
    }

    public function test_closing_at_a_cut_time_splits_the_sales_and_opens_the_next_shift_from_that_instant(): void
    {
        $this->seedDay();
        $admin = $this->admin([], 'coupon_cash_close.view', 'coupon_cash_close.close');
        $this->actingAs($admin, 'admin');
        Shifts::current($this->loc);

        $cut = '2026-11-20T13:00';
        $this->post(route('admin.shifts.close', $this->loc->id), ['until' => $cut, 'note' => 'Turno mañana'])->assertRedirect();

        $one = Shift::where('location_id', $this->loc->id)->where('number', 1)->firstOrFail();
        $two = Shift::where('location_id', $this->loc->id)->where('number', 2)->firstOrFail();
        $this->assertSame('closed', $one->status);
        $this->assertSame('open', $two->status);
        $this->assertSame('2026-11-20 13:00:00', $one->ends_at->format('Y-m-d H:i:s'));
        $this->assertEquals($one->ends_at, $two->starts_at);
        $this->assertSame($admin->id, $one->closed_by_admin_id);
        $this->assertSame('Turno mañana', $one->note);

        // Totales de la mañana (hasta las 13:00 sin incluirlas): 100 cobrado, 20 reversado, 80 neto, 15 recargado
        $this->assertSame([100.0, 20.0, 80.0, 15.0, 3], [(float) $one->redeemed, (float) $one->reversed, (float) $one->net, (float) $one->topups, $one->redemptions]);
        $this->assertEqualsCanonicalizing(['ana', 'luis'], array_column($one->details['cashiers'], 'name'));
        $byD = collect($one->details['dispensers'])->keyBy('dispenser');
        $this->assertEquals(80.0, $byD[1]['net']);
        $this->assertEquals(0.0, $byD[2]['net']);

        // Cada movimiento queda guardado en su turno; el de las 13:00 exactas y lo posterior, en el siguiente
        $this->assertSame(5, DB::table('coupon_transactions')->where('shift_id', $one->id)->count());
        $this->assertSame(2, DB::table('coupon_transactions')->where('shift_id', $two->id)->count());
        $this->assertSame(0, DB::table('coupon_transactions')->whereNull('shift_id')->count());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'shift_close', 'entity_id' => (string) $this->loc->id]);

        // Se puede cerrar otra vez, tantas como se quiera: el turno 2 hasta las 15:00 deja lo de las 15:30 para el 3
        $this->post(route('admin.shifts.close', $this->loc->id), ['until' => '2026-11-20T15:00'])->assertRedirect();
        $this->assertSame(1, DB::table('coupon_transactions')->where('shift_id', $two->id)->count());
        $three = Shift::where('location_id', $this->loc->id)->where('number', 3)->firstOrFail();
        $this->assertSame('open', $three->status);
        $this->assertSame(1, DB::table('coupon_transactions')->where('shift_id', $three->id)->count());
        $this->assertSame(1, Shift::where('location_id', $this->loc->id)->where('status', 'open')->count());
    }

    public function test_the_preview_says_what_enters_and_what_stays_for_the_next_shift(): void
    {
        $this->seedDay();
        $this->actingAs($this->admin([], 'coupon_cash_close.view', 'coupon_cash_close.close'), 'admin');

        $page = $this->get(route('admin.shifts.location', [$this->loc->id, 'until' => '2026-11-20T13:00']))->assertOk()
            ->assertSee('Entra en este turno')->assertSee('Queda para el turno 2')->assertSee('Surtidor 1')->assertSee('ana')->assertSee('luis');
        $this->assertStringContainsString('2 cobros', $page->getContent()); // las 13:00 y las 15:30 pasarían al siguiente

        $this->get(route('admin.shifts.location', [$this->loc->id, 'until' => '2026-11-20T05:00']))->assertOk()->assertSee('debe ser posterior al inicio');
    }

    public function test_invalid_cut_times_are_rejected(): void
    {
        $this->seedDay();
        $this->actingAs($this->admin([], 'coupon_cash_close.view', 'coupon_cash_close.close'), 'admin');
        Shifts::current($this->loc);

        $this->post(route('admin.shifts.close', $this->loc->id), ['until' => '2026-11-20T08:10'])->assertSessionHasErrors('close');   // = inicio del turno
        $this->post(route('admin.shifts.close', $this->loc->id), ['until' => '2026-11-20T19:00'])->assertSessionHasErrors('close');   // futura
        $this->assertSame(1, Shift::where('location_id', $this->loc->id)->count());
        $this->assertSame('open', Shift::where('location_id', $this->loc->id)->first()->status);
    }

    public function test_a_closed_shift_is_immutable_when_more_sales_arrive_and_exports_csv(): void
    {
        $this->seedDay();
        $this->actingAs($this->admin([], 'coupon_cash_close.view', 'coupon_cash_close.close', 'coupon_cash_close.export'), 'admin');
        $this->post(route('admin.shifts.close', $this->loc->id), ['until' => '2026-11-20T13:00']);
        $one = Shift::where('number', 1)->where('location_id', $this->loc->id)->firstOrFail();

        $this->tx('REDEEM', '999.00', $this->ana, '2026-11-20 17:00:00');
        $this->assertSame('80.00', number_format((float) $one->fresh()->net, 2, '.', ''));

        $csv = $this->get(route('admin.shifts.show', [$one, 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Turno 1', $csv);
        $this->assertStringContainsString('TOTAL,3,100.00,20.00,80.00,15.00', $csv);
        $this->assertStringContainsString('Surtidor 1', $csv);
        $this->get(route('admin.shifts.show', $one))->assertOk()->assertSee('Turno inmutable');
    }

    public function test_new_sales_from_the_app_are_stored_in_the_open_shift_of_their_location(): void
    {
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => $this->loc->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);
        DB::table('coupons')->insert(['coupon_number' => 'A1', 'coupon_token' => 'tok-A1', 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => '100.00', 'coupon_balance' => '100.00', 'coupon_expire_date' => now()->addYear()->toDateString()]);
        $u = User::factory()->create(['role' => 'MANAGER', 'location_id' => $this->loc->id]);
        Sanctum::actingAs($u, $u->couponAbilities());
        $h = ['Device-Fingerprint' => $device->device_fingerprint];

        $sale = $this->postJson('/api/transaction', ['amount' => 10, 'coupons' => ['A1'], 'tokens' => ['tok-A1']], $h)->assertOk();
        $open = Shifts::current($this->loc);
        $this->assertSame($open->id, (int) DB::table('coupon_transactions')->where('transaction_id', $sale->json('transaction_id'))->value('shift_id'));

        // Una reversa queda en el turno en que se hace, no en el de la venta original
        $admin = $this->admin([], 'coupon_cash_close.view', 'coupon_cash_close.close');
        Carbon::setTestNow('2026-11-20 19:00:00');
        $this->actingAs($admin, 'admin')->post(route('admin.shifts.close', $this->loc->id), ['until' => '2026-11-20T18:30']);
        $other = User::factory()->create(['role' => 'MANAGER', 'location_id' => $this->loc->id]);
        Sanctum::actingAs($other, $other->couponAbilities());
        $this->postJson('/api/transactions/'.$sale->json('transaction_id').'/reverse', ['reason' => 'Cobro equivocado'], $h)->assertOk();
        $reversal = DB::table('coupon_transactions')->where('kind', 'REVERSAL')->first();
        $this->assertNotSame($open->id, (int) $reversal->shift_id);
        $this->assertSame(2, (int) Shift::find($reversal->shift_id)->number);
    }

    public function test_location_scope_and_permissions_apply_to_shifts(): void
    {
        $other = Location::factory()->create(['is_active' => true, 'name' => 'Zona 2']);
        $this->tx('REDEEM', '10.00', $this->ana, '2026-11-20 09:00:00', null, null, $other);
        $this->seedDay();

        $restricted = $this->admin([$this->loc], 'coupon_cash_close.view', 'coupon_cash_close.close');
        $this->actingAs($restricted, 'admin');
        $html = $this->get(route('admin.shifts.index'))->assertOk()->getContent();
        $main = substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
        $this->assertStringContainsString('Zona 1', $main);
        $this->assertStringNotContainsString('Zona 2', $main);
        $this->get(route('admin.shifts.location', $other->id))->assertNotFound();
        $this->post(route('admin.shifts.close', $other->id), ['until' => '2026-11-20T12:00'])->assertNotFound();

        // Sin el permiso de cerrar no hay cierre; con alcance por clientes recortado tampoco
        $viewer = $this->admin([], 'coupon_cash_close.view');
        $this->actingAs($viewer, 'admin')->post(route('admin.shifts.close', $this->loc->id), ['until' => '2026-11-20T12:00'])->assertForbidden();
        $byCustomer = $this->admin([], 'coupon_cash_close.view', 'coupon_cash_close.close');
        $byCustomer->forceFill(['customer_scope' => 'selected'])->save();
        $this->actingAs($byCustomer, 'admin')->post(route('admin.shifts.close', $this->loc->id), ['until' => '2026-11-20T12:00'])->assertForbidden();
        $this->actingAs($this->admin(), 'admin')->get(route('admin.shifts.index'))->assertForbidden();
    }
}
