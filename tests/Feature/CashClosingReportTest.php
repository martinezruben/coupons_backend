<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashClosingReportTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'cierre_caja.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'cash-viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo('cierre_caja.view');
        $this->admin = AdminUser::factory()->create();
        $this->admin->assignRole($role);
    }

    private function seedShiftWithTransactions(): array
    {
        $location = Location::create(['id' => (string) \Str::uuid(), 'name' => 'Sede A', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) \Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-caja',
            'is_enabled' => true,
        ]);
        $user = User::factory()->create();

        $shift = Shift::create([
            'id' => (string) \Str::uuid(),
            'location_id' => $location->id,
            'device_id' => $device->id,
            'user_id' => $user->id,
            'shift_number' => 'T7',
            'start_time' => '2026-05-10 08:00:00',
            'end_time' => '2026-05-10 17:00:00',
            'opening_balance' => 100,
            'closing_balance' => 450,
        ]);

        // en el turno: 200 CASH + 150 CARD
        $tx = Transaction::create([
            'id' => (string) \Str::uuid(),
            'external_id' => 'EXT-CAJA-1',
            'location_id' => $location->id,
            'device_id' => $device->id,
            'user_id' => $user->id,
            'shift_id' => 'T7',
            'status' => 'PAID',
            'total' => 350,
            'occurred_at' => '2026-05-10 12:00:00',
            'turn_number' => 7,
            'is_synced' => true,
        ]);
        TransactionPayment::create(['transaction_id' => $tx->id, 'payment_method' => 'CASH', 'amount' => 200]);
        TransactionPayment::create(['transaction_id' => $tx->id, 'payment_method' => 'CARD', 'amount' => 150]);

        // fuera del turno: otra localidad y otra ventana
        $other = Location::create(['id' => (string) \Str::uuid(), 'name' => 'Sede B', 'is_active' => true]);
        Transaction::create([
            'id' => (string) \Str::uuid(),
            'external_id' => 'EXT-OTRO',
            'location_id' => $other->id,
            'device_id' => $device->id,
            'user_id' => $user->id,
            'shift_id' => 'T7',
            'status' => 'PAID',
            'total' => 999,
            'occurred_at' => '2026-05-10 12:00:00',
            'turn_number' => 7,
            'is_synced' => true,
        ]);

        return [$shift, $location, $user, $device];
    }

    public function test_arqueo_sums_payments_per_shift_and_detects_difference(): void
    {
        [$shift] = $this->seedShiftWithTransactions();

        $res = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.cierre-caja.index', ['filter' => ['date_from' => '2026-05-10', 'date_to' => '2026-05-10']]))
            ->assertOk()
            ->assertSee('Sede A')
            ->assertSee('T7');

        $html = $res->getContent();
        // CASH 200 (esperado 300 = 100 apertura + 200), CARD 150, total 350,
        // diferencia 450 - 300 = 150
        $this->assertStringContainsString('$350.00', $html);
        $this->assertStringContainsString('$300.00', $html);
        $this->assertStringContainsString('$150.00', $html);
    }

    public function test_arqueo_filters_by_location_and_date(): void
    {
        [$shift, $location] = $this->seedShiftWithTransactions();

        // localidad equivocada: sin filas
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.cierre-caja.index', ['filter' => ['location_id' => 'no-existe-loc']]))
            ->assertOk()
            ->assertSee('No hay turnos');

        // fecha fuera de rango: sin filas
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.cierre-caja.index', ['filter' => ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']]))
            ->assertOk()
            ->assertSee('No hay turnos');

        // fecha correcta: muestra el turno
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.cierre-caja.index', ['filter' => ['date_from' => '2026-05-10', 'date_to' => '2026-05-10']]))
            ->assertOk()
            ->assertSee('T7');
    }

    public function test_arqueo_export_downloads_xlsx(): void
    {
        $this->seedShiftWithTransactions();

        $res = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.cierre-caja.export'));

        $res->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $res->headers->get('Content-Type'),
        );
        $this->assertNotEmpty($res->streamedContent());
    }

    public function test_guest_cannot_access_arqueo(): void
    {
        $this->get(route('admin.cierre-caja.index'))->assertRedirect();
        $this->get(route('admin.cierre-caja.export'))->assertRedirect();
    }
}
