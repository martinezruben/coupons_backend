<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\TopUpRule;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTopUpAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    private function qr(string $number = 'Q1', string $balance = '10.00'): int
    {
        return DB::table('coupons')->insertGetId([
            'coupon_number' => $number, 'coupon_token' => 'tok-'.$number, 'coupon_active' => 1,
            'coupon_amount' => $balance, 'coupon_balance' => $balance, 'coupon_expire_date' => now()->addYear()->toDateString(),
        ], 'coupon_id');
    }

    private function tx(string $kind, string $amount, array $extra = []): void
    {
        DB::table('coupon_transactions')->insert(array_merge([
            'coupon_id' => 1, 'coupon_number' => 'Q1', 'transaction_amount' => $amount, 'coupon_type' => 'QRCODE', 'kind' => $kind,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    // ---- Recarga desde el panel

    public function test_panel_topup_needs_permission_and_records_the_admin_and_note(): void
    {
        $id = $this->qr('Q1', '10.00');
        $url = route('admin.coupons.topup', ['qr', $id]);

        $this->actingAs($this->admin('coupons.view'), 'admin')->post($url, ['amount' => 20])->assertForbidden();

        $admin = $this->admin('coupons.view', 'coupons.topup');
        $this->actingAs($admin, 'admin')->post($url, ['amount' => 20, 'note' => 'Pago en efectivo ref 123'])->assertSessionHas('status');

        $this->assertSame('30.00', number_format((float) DB::table('coupons')->value('coupon_balance'), 2, '.', ''));
        $this->assertDatabaseHas('coupon_transactions', ['kind' => 'TOPUP', 'admin_user_id' => $admin->id, 'transaction_amount' => '20.00']);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'topup', 'admin_user_id' => $admin->id, 'reason' => 'Recarga de 20.00: Pago en efectivo ref 123']);
    }

    public function test_panel_topup_validates_amount_and_respects_weekday_and_daily_rules(): void
    {
        $id = $this->qr('Q1', '10.00');
        $url = route('admin.coupons.topup', ['qr', $id]);
        $this->actingAs($this->admin('coupons.view', 'coupons.topup'), 'admin');

        $this->post($url, ['amount' => -1])->assertSessionHasErrors('amount');
        $this->post($url, ['amount' => 1.005])->assertSessionHasErrors('amount');

        TopUpRule::create(['name' => 'Solo lunes', 'type' => 'weekday', 'weekdays' => '1']);
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00')); // jueves
        $this->post($url, ['amount' => 5])->assertSessionHasErrors('coupon');

        $this->travelTo(Carbon::parse('2026-11-16 10:00:00')); // lunes
        TopUpRule::create(['name' => 'Tope', 'type' => 'daily_amount', 'max_daily_amount' => '30.00']);
        $this->post($url, ['amount' => 25])->assertSessionHas('status');
        $this->post($url, ['amount' => 6])->assertSessionHasErrors('coupon'); // 25 + 6 > 30
    }

    public function test_panel_topup_ignores_location_rules_because_it_has_no_location(): void
    {
        $id = $this->qr('Q1');
        TopUpRule::create(['name' => 'Solo sede', 'type' => 'location', 'location_id' => Location::factory()->create()->id]);

        $this->actingAs($this->admin('coupons.view', 'coupons.topup'), 'admin')
            ->post(route('admin.coupons.topup', ['qr', $id]), ['amount' => 5])->assertSessionHas('status');
    }

    // ---- Reglas (CRUD)

    public function test_topup_rules_crud_validates_by_type_and_clears_unused_fields(): void
    {
        $location = Location::factory()->create();
        $this->actingAs($this->admin('topup_rules.view', 'topup_rules.edit', 'topup_rules.delete'), 'admin');
        $store = fn (array $data) => $this->post(route('admin.screens.store', 'topup-rules'), $data);

        $store(['name' => 'Sin sede', 'type' => 'location', 'is_active' => 1])->assertSessionHasErrors('location_id');
        $store(['name' => 'Sin días', 'type' => 'weekday', 'is_active' => 1])->assertSessionHasErrors('weekdays');
        $store(['name' => 'Días malos', 'type' => 'weekday', 'weekdays' => '1,8', 'is_active' => 1])->assertSessionHasErrors('weekdays');
        $store(['name' => 'Sin tope', 'type' => 'daily_amount', 'is_active' => 1])->assertSessionHasErrors('max_daily_amount');
        $store(['name' => 'Tipo malo', 'type' => 'otro', 'is_active' => 1])->assertSessionHasErrors('type');
        $this->assertDatabaseCount('topup_rules', 0);

        $store(['name' => 'Sede A', 'type' => 'location', 'location_id' => $location->id, 'weekdays' => '1,2', 'max_daily_amount' => '5', 'is_active' => 1])->assertSessionHasNoErrors();
        $rule = TopUpRule::first();
        $this->assertSame($location->id, $rule->location_id);
        $this->assertNull($rule->weekdays, 'cada tipo guarda solo sus datos');
        $this->assertNull($rule->max_daily_amount);

        $this->put(route('admin.screens.update', ['topup-rules', $rule->id]), ['name' => 'Sede A', 'type' => 'weekday', 'weekdays' => '1,2,3', 'is_active' => 0])->assertSessionHasNoErrors();
        $rule->refresh();
        $this->assertSame('weekday', $rule->type);
        $this->assertNull($rule->location_id);
        $this->assertFalse($rule->is_active);

        $this->delete(route('admin.screens.destroy', ['topup-rules', $rule->id]))->assertRedirect();
        $this->assertDatabaseCount('topup_rules', 0);
    }

    public function test_topup_rules_screen_requires_permission(): void
    {
        $this->actingAs($this->admin('coupons.view'), 'admin')->get(route('admin.screens.index', 'topup-rules'))->assertForbidden();
        $this->actingAs($this->admin('topup_rules.view'), 'admin')->get(route('admin.screens.index', 'topup-rules'))->assertOk();
    }

    // ---- Transacciones y dashboard

    public function test_transactions_grid_labels_the_kind_and_only_offers_reverse_for_redemptions(): void
    {
        $this->qr();
        $this->tx('REDEEM', '5.00');
        $this->tx('TOPUP', '20.00');

        $html = $this->actingAs($this->admin('coupon_transactions.view', 'coupon_transactions.reverse'), 'admin')
            ->get(route('admin.screens.index', 'coupon-transactions'))->assertOk()
            ->assertSee('cobro')->assertSee('recarga')->getContent();

        $this->assertSame(1, substr_count($html, '>Revertir<'), 'solo el cobro se puede revertir');
    }

    public function test_dashboard_separates_net_redemptions_from_topups(): void
    {
        $this->qr();
        $this->tx('REDEEM', '30.00');
        $this->tx('REVERSAL', '-10.00', ['reversed_of' => 99]);
        $this->tx('TOPUP', '500.00');

        $this->actingAs($this->admin(), 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Recargado hoy')->assertSee('$500.00')->assertSee('$20.00') // 30 - 10 neto
            ->assertDontSee('$520.00')->assertDontSee('$530.00');
    }

    // ---- Reportes

    private function seedReportData(): array
    {
        $a = Location::factory()->create(['name' => 'Sede Norte', 'is_active' => true]);
        $b = Location::factory()->create(['name' => 'Sede Sur', 'is_active' => true]);
        $this->qr();
        $this->travelTo(Carbon::parse('2026-11-10 12:00:00'));
        $this->tx('REDEEM', '30.00', ['location_id' => $a->id]);
        $this->tx('REDEEM', '20.00', ['location_id' => $b->id, 'coupon_type' => 'CODBAR']);
        $this->tx('REVERSAL', '-10.00', ['location_id' => $a->id, 'reversed_of' => 1]);
        $this->tx('TOPUP', '100.00', ['location_id' => $b->id]);
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00'));
        $this->tx('REDEEM', '15.00', ['location_id' => $a->id]);
        $this->travelTo(Carbon::parse('2026-11-14 09:00:00'));

        return [$a, $b];
    }

    public function test_report_groups_by_location_with_correct_totals(): void
    {
        $this->seedReportData();
        $this->actingAs($this->admin('coupon_reports.view'), 'admin');

        $res = $this->get(route('admin.reports.redemptions', ['from' => '2026-11-01', 'to' => '2026-11-14', 'group' => 'location']))->assertOk();
        $res->assertSee('Sede Norte')->assertSee('Sede Sur');

        $rows = $res->viewData('rows')->keyBy('label');
        $this->assertEquals(45.0, $rows['Sede Norte']->redeemed);
        $this->assertEquals(10.0, $rows['Sede Norte']->reversed);
        $this->assertEquals(35.0, $rows['Sede Norte']->net);
        $this->assertEquals(2, $rows['Sede Norte']->redemptions);
        $this->assertEquals(20.0, $rows['Sede Sur']->net);
        $this->assertEquals(100.0, $rows['Sede Sur']->topups);
        $this->assertEquals(['redeemed' => 65.0, 'reversed' => 10.0, 'net' => 55.0, 'topups' => 100.0, 'redemptions' => 3], $res->viewData('totals'));
    }

    public function test_report_groups_by_day_and_filters_by_date_type_and_location(): void
    {
        [$a] = $this->seedReportData();
        $this->actingAs($this->admin('coupon_reports.view'), 'admin');

        $days = $this->get(route('admin.reports.redemptions', ['from' => '2026-11-01', 'to' => '2026-11-14', 'group' => 'day']))->viewData('rows');
        $this->assertSame(['2026-11-10', '2026-11-11'], $days->pluck('label')->all());
        $this->assertEquals(40.0, $days[0]->net); // 30 + 20 - 10
        $this->assertEquals(15.0, $days[1]->net);

        $onlyNov11 = $this->get(route('admin.reports.redemptions', ['from' => '2026-11-11', 'to' => '2026-11-11']))->viewData('totals');
        $this->assertEquals(15.0, $onlyNov11['net']);

        $bars = $this->get(route('admin.reports.redemptions', ['from' => '2026-11-01', 'to' => '2026-11-14', 'coupon_type' => 'CODBAR']))->viewData('totals');
        $this->assertEquals(20.0, $bars['net']);

        $loc = $this->get(route('admin.reports.redemptions', ['from' => '2026-11-01', 'to' => '2026-11-14', 'location_id' => $a->id]))->viewData('totals');
        $this->assertEquals(35.0, $loc['net']);
    }

    public function test_report_other_groupings_work(): void
    {
        $user = User::factory()->create(['username' => 'cajero1']);
        $device = Device::factory()->create(['name' => 'Caja 1']);
        License::factory()->create(['device_id' => $device->id]);
        $this->qr();
        $this->tx('REDEEM', '12.00', ['user_id' => $user->id, 'device_id' => $device->id]);
        $this->tx('REDEEM', '8.00');
        $this->actingAs($this->admin('coupon_reports.view'), 'admin');

        $byUser = $this->get(route('admin.reports.redemptions', ['group' => 'user']))->viewData('rows')->keyBy('label');
        $this->assertEquals(12.0, $byUser['cajero1']->net);
        $this->assertEquals(8.0, $byUser['Sin usuario']->net);

        $byDevice = $this->get(route('admin.reports.redemptions', ['group' => 'device']))->viewData('rows')->keyBy('label');
        $this->assertEquals(12.0, $byDevice['Caja 1']->net);
        $this->assertEquals(8.0, $byDevice['Sin dispositivo']->net);

        $byType = $this->get(route('admin.reports.redemptions', ['group' => 'type']))->viewData('rows');
        $this->assertSame(['QR'], $byType->pluck('label')->all());
    }

    public function test_report_csv_export(): void
    {
        $this->seedReportData();
        $this->actingAs($this->admin('coupon_reports.view'), 'admin');

        $res = $this->get(route('admin.reports.redemptions', ['from' => '2026-11-01', 'to' => '2026-11-14', 'group' => 'location', 'export' => 'csv']))->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Localidad,"Cobros (n.º)",Cobrado,Reversado,"Neto cobrado",Recargado', $csv);
        $this->assertStringContainsString('"Sede Norte",2,45.00,10.00,35.00,0.00', $csv);
        $this->assertStringContainsString('TOTAL,3,65.00,10.00,55.00,100.00', $csv);
        $this->assertStringContainsString('attachment', $res->headers->get('content-disposition'));
    }

    public function test_report_requires_permission_and_validates_filters(): void
    {
        $this->actingAs($this->admin('coupons.view'), 'admin')->get(route('admin.reports.redemptions'))->assertForbidden();

        $this->actingAs($this->admin('coupon_reports.view'), 'admin');
        $this->get(route('admin.reports.redemptions', ['group' => 'hack']))->assertSessionHasErrors('group');
        $this->get(route('admin.reports.redemptions', ['from' => '2026-11-10', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->get(route('admin.reports.redemptions', ['location_id' => "1' OR 1=1"]))->assertSessionHasErrors('location_id');
    }

    public function test_report_appears_in_the_menu_and_the_matrix_and_seeder_grants_it(): void
    {
        $this->actingAs($this->admin('coupon_reports.view'), 'admin')->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Reportes de canjes')->assertSee(route('admin.reports.redemptions'));

        $this->seed(RbacSeeder::class);
        $manager = Role::findByName('ops-manager', 'admin');
        $this->assertTrue($manager->hasPermissionTo('coupon_reports.view'));
        $this->assertTrue($manager->hasPermissionTo('topup_rules.view'));
        $this->assertFalse($manager->hasPermissionTo('topup_rules.edit'));
        $this->assertFalse($manager->hasPermissionTo('coupons.topup'), 'recargar es solo super-admin');
    }
}
