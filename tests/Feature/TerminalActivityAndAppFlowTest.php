<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessContingencyReminders;
use App\Jobs\ScheduleContingencyReminderJob;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TerminalActivityAndAppFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Location, 1: Device, 2: array<string, string>} */
    private function terminal(int $dispensers = 3): array
    {
        $loc = Location::factory()->create(['is_active' => true, 'dispensers' => $dispensers, 'phone' => '809-555-0100']);
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => $loc->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);

        // Las cabeceras que envía la app 1.1.x en cada petición
        return [$loc, $device, [
            'Accept' => 'application/json', 'App-Version' => '1.1.0',
            'Device-Fingerprint' => $device->device_fingerprint, 'Device-Token' => $device->createToken('pos-device')->plainTextToken,
        ]];
    }

    private function coupon(string $number, string $balance = '100.00'): void
    {
        DB::table('coupons')->insert(['coupon_number' => $number, 'coupon_token' => 'tok-'.$number, 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => $balance, 'coupon_balance' => $balance, 'coupon_expire_date' => now()->addYear()->toDateString()]);
    }

    public function test_the_whole_app_flow_uploads_the_sale_and_marks_the_terminal_as_active(): void
    {
        // Valores por defecto de producción: vínculo con el terminal, surtidor e idempotencia obligatorios
        config(['coupons.require_device_binding' => true, 'coupons.require_device_token' => true, 'coupons.require_dispenser' => true, 'coupons.require_idempotency_key' => true]);
        [$loc, $device, $h] = $this->terminal();
        User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'role' => 'CASHIER', 'location_id' => $loc->id]);
        $this->coupon('A1');

        $login = $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'device_name' => $device->device_fingerprint], $h)->assertOk()
            ->assertJsonPath('dispensers', 3)->assertJsonPath('receipt.phone', '809-555-0100');
        $auth = $h + ['Authorization' => 'Bearer '.$login->json('token')];

        $this->getJson('/api/profile', $auth)->assertOk();
        $this->getJson('/api/balance?coupon_number=A1&coupon_token=tok-A1', $auth)->assertOk()->assertJsonPath('balance', '100.00');

        $key = '9b2f7c1e-4d6a-4b8e-9a3c-1f2e3d4c5b6a';
        $sale = $this->postJson('/api/transaction', ['amount' => '30.50', 'coupons' => ['A1'], 'tokens' => ['tok-A1'], 'dispenser' => 2], $auth + ['Idempotency-Key' => $key])->assertOk()
            ->assertJsonPath('dispenser', 2)->assertJsonPath('sumtransaction_amount', '30.50')
            ->assertJsonStructure(['transaction_id', 'transaction_ids', 'date', 'coupons' => [['coupon_number', 'balance', 'balance_initial', 'transaction_amount', 'transaction_id']]]);

        // La venta quedó registrada con cajero, terminal, localidad y surtidor
        $this->assertDatabaseHas('coupon_transactions', ['transaction_id' => $sale->json('transaction_id'), 'kind' => 'REDEEM', 'transaction_amount' => '30.50', 'device_id' => $device->id, 'location_id' => $loc->id, 'dispenser' => 2, 'location_source' => 'device']);
        $this->assertSame('69.50', number_format((float) DB::table('coupons')->where('coupon_number', 'A1')->value('coupon_balance'), 2, '.', ''));

        // Reintento de la app (se cayó la red al responder): misma clave, mismo resultado, sin cobrar dos veces
        $this->postJson('/api/transaction', ['amount' => '30.50', 'coupons' => ['A1'], 'tokens' => ['tok-A1'], 'dispenser' => 2], $auth + ['Idempotency-Key' => $key])->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('transaction_id', $sale->json('transaction_id'));
        $this->assertSame(1, DB::table('coupon_transactions')->where('kind', 'REDEEM')->count());

        // El terminal quedó marcado como activo, con su versión y su último cobro
        $d = $device->fresh();
        $this->assertNotNull($d->last_sync_at);
        $this->assertNotNull($d->last_transaction_at);
        $this->assertSame('1.1.0', $d->app_version);
        $this->assertNotNull($loc->fresh()->last_sync_at);
    }

    public function test_a_terminal_that_only_browses_is_active_but_has_no_transactions(): void
    {
        [$loc, $device, $h] = $this->terminal();
        User::factory()->create(['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!', 'location_id' => $loc->id]);
        $this->coupon('A1');
        $token = $this->postJson('/api/login', ['username' => 'caja@example.com', 'password' => 'Correct-Horse-9!'], $h)->json('token');

        $this->withToken($token)->getJson('/api/balance?coupon_number=A1&coupon_token=tok-A1', $h)->assertOk();

        $this->assertNotNull($device->fresh()->last_sync_at);
        $this->assertNull($device->fresh()->last_transaction_at); // consultó saldo pero no ha cobrado
    }

    public function test_the_activity_columns_show_in_the_devices_grid(): void
    {
        [, $device] = $this->terminal();
        $device->forceFill(['last_transaction_at' => now()->subHour(), 'last_sync_at' => now()])->save();
        foreach (['devices.view'] as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('devices.view');

        $this->actingAs($admin, 'admin')->get(route('admin.screens.index', 'devices'))->assertOk()->assertSee('Último cobro')->assertSee('Última actividad');
    }

    public function test_the_contingency_reminders_command_no_longer_needs_sqlite_functions(): void
    {
        Queue::fake();
        (SystemParameter::query()->first() ?? SystemParameter::factory()->create())->update(['contingency_enabled' => true, 'contingency_resend_hours' => 6]);
        $old = Location::factory()->create();
        $recent = Location::factory()->create();
        $never = Location::factory()->create();
        DB::table('locations')->where('id', $old->id)->update(['contingency_started_at' => now()->subDay(), 'contingency_reminder_sent_at' => now()->subHours(7)]);
        DB::table('locations')->where('id', $recent->id)->update(['contingency_started_at' => now()->subDay(), 'contingency_reminder_sent_at' => now()->subHours(2)]);
        DB::table('locations')->where('id', $never->id)->update(['contingency_started_at' => now()->subDay(), 'contingency_reminder_sent_at' => null]);

        $this->artisan('contingency:process-reminders')->assertSuccessful();

        Queue::assertPushed(ScheduleContingencyReminderJob::class, 2); // la de hace 7 h y la que nunca recibió aviso
        $this->assertTrue(class_exists(ProcessContingencyReminders::class));
    }
}
