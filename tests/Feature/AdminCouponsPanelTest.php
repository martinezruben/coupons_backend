<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\User;
use App\Support\AdminRbac;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminCouponsPanelTest extends TestCase
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

    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $admin->assignRole(Role::findOrCreate('super-admin', 'admin'));

        return $admin;
    }

    private function qr(string $number = 'Q1', string $balance = '50.00', array $extra = []): int
    {
        return DB::table('coupons')->insertGetId(array_merge([
            'coupon_number' => $number, 'coupon_token' => 'secret-token-'.$number, 'coupon_active' => 1,
            'coupon_amount' => '100.00', 'coupon_balance' => $balance,
            'coupon_expire_date' => now()->addYear()->toDateString(),
        ], $extra), 'coupon_id');
    }

    private function balance(string $table, int $id): string
    {
        return number_format((float) DB::table($table)->where('coupon_id', $id)->value('coupon_balance'), 2, '.', '');
    }

    public function test_grids_render_and_never_show_qr_tokens(): void
    {
        $this->qr('Q1');
        DB::table('coupons_codbar')->insert(['coupon_number' => 'B1', 'coupon_active' => 1, 'coupon_amount' => '30.00', 'coupon_balance' => '30.00']);

        $this->actingAs($this->admin('coupons.view', 'coupon_barcodes.view'), 'admin');

        $res = $this->get(route('admin.screens.index', 'coupons'))->assertOk()->assertSee('Q1')->assertSee('$50.00');
        $this->assertStringNotContainsString('secret-token', $res->getContent());
        $this->get(route('admin.screens.index', 'coupon-barcodes'))->assertOk()->assertSee('B1');
    }

    public function test_grids_require_their_view_permission(): void
    {
        $this->actingAs($this->admin('coupon_barcodes.view'), 'admin')
            ->get(route('admin.screens.index', 'coupons'))->assertForbidden();
    }

    public function test_dashboard_shows_coupon_kpis(): void
    {
        $this->qr('Q1', '50.00');
        $this->qr('Q2', '20.00', ['coupon_active' => 0]);
        DB::table('coupon_transactions')->insert([
            'coupon_id' => 1, 'coupon_number' => 'Q1', 'transaction_amount' => '25.00', 'coupon_type' => 'QRCODE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Saldo pendiente')
            ->assertSee('$50.00')   // solo el cupón activo
            ->assertSee('$25.00')   // cobrado hoy
            ->assertSee('Q1');
    }

    public function test_issue_qr_coupons_returns_tokens_once_and_audits(): void
    {
        $admin = $this->admin('coupons.view', 'coupons.issue');
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.coupons.issue', 'qr'))->assertOk();

        $res = $this->post(route('admin.coupons.issue.store', 'qr'), ['quantity' => 3, 'amount' => '25.50', 'prefix' => 'nav', 'expire_date' => now()->addMonth()->toDateString()])
            ->assertOk()->assertSee('Descargar CSV')->assertSee('@coupon=NAV', false);

        $this->assertDatabaseCount('coupons', 3);
        $row = DB::table('coupons')->first();
        $this->assertStringStartsWith('NAV', $row->coupon_number);
        $this->assertSame(32, strlen($row->coupon_token));
        $this->assertSame('25.50', number_format((float) $row->coupon_balance, 2, '.', ''));
        $this->assertStringContainsString('@token='.$row->coupon_token, $res->getContent());
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'issue', 'admin_user_id' => $admin->id, 'new_value' => '3']);

        // El listado nunca vuelve a mostrar el token
        $this->actingAs($admin, 'admin');
        $this->get(route('admin.screens.index', 'coupons'))->assertDontSee($row->coupon_token);
    }

    public function test_issue_barcode_coupons_has_no_token(): void
    {
        $this->actingAs($this->admin('coupon_barcodes.view', 'coupon_barcodes.issue'), 'admin');

        $this->post(route('admin.coupons.issue.store', 'barcode'), ['quantity' => 2, 'amount' => 10])->assertOk();

        $this->assertDatabaseCount('coupons_codbar', 2);
        $this->assertSame(12, strlen((string) DB::table('coupons_codbar')->value('coupon_number')));
    }

    public function test_issue_validates_input_and_requires_permission(): void
    {
        $this->actingAs($this->admin('coupons.view'), 'admin');
        $this->get(route('admin.coupons.issue', 'qr'))->assertForbidden();
        $this->post(route('admin.coupons.issue.store', 'qr'), ['quantity' => 1, 'amount' => 10])->assertForbidden();

        $this->actingAs($this->admin('coupons.view', 'coupons.issue'), 'admin');
        foreach ([
            ['quantity' => 0, 'amount' => 10],
            ['quantity' => 501, 'amount' => 10],
            ['quantity' => 1, 'amount' => -1],
            ['quantity' => 1, 'amount' => 1.005],
            ['quantity' => 1, 'amount' => 10, 'expire_date' => now()->subDay()->toDateString()],
            ['quantity' => 1, 'amount' => 10, 'prefix' => 'bad prefix!'],
        ] as $body) {
            $this->post(route('admin.coupons.issue.store', 'qr'), $body)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('coupons', 0);
    }

    public function test_toggle_requires_permission_reason_and_audits(): void
    {
        $id = $this->qr('Q1');
        $url = route('admin.coupons.toggle', ['qr', $id]);

        $this->actingAs($this->admin('coupons.view'), 'admin')->post($url, ['active' => 0, 'reason' => 'Fraude'])->assertForbidden();
        $this->assertSame(1, (int) DB::table('coupons')->value('coupon_active'));

        $admin = $this->admin('coupons.view', 'coupons.toggle');
        $this->actingAs($admin, 'admin')->post($url, ['active' => 0])->assertSessionHasErrors('reason');
        $this->post($url, ['active' => 0, 'reason' => 'Fraude detectado'])->assertRedirect(route('admin.screens.index', 'coupons'));

        $this->assertSame(0, (int) DB::table('coupons')->value('coupon_active'));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'deactivate', 'coupon_number' => 'Q1', 'admin_user_id' => $admin->id, 'reason' => 'Fraude detectado']);
    }

    public function test_adjust_balance_is_permissioned_capped_and_audited(): void
    {
        $id = $this->qr('Q1', '10.00');
        $url = route('admin.coupons.adjust', ['qr', $id]);

        $this->actingAs($this->admin('coupons.view', 'coupons.toggle'), 'admin')->post($url, ['balance' => 20, 'reason' => 'Ajuste de caja'])->assertForbidden();
        $this->assertSame('10.00', $this->balance('coupons', $id));

        $admin = $this->admin('coupons.view', 'coupons.adjust');
        $this->actingAs($admin, 'admin');
        $this->post($url, ['balance' => 20, 'reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post($url, ['balance' => -5, 'reason' => 'Ajuste de caja'])->assertSessionHasErrors('balance');
        $this->post($url, ['balance' => 100.01, 'reason' => 'Ajuste de caja'])->assertSessionHasErrors('coupon'); // supera el monto original
        $this->post($url, ['balance' => 40, 'reason' => 'Ajuste de caja'])->assertSessionHas('status');

        $this->assertSame('40.00', $this->balance('coupons', $id));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'restore', 'admin_user_id' => $admin->id, 'old_value' => '10.00', 'new_value' => '40.00']);
    }

    public function test_reverse_from_panel_restores_balance_once_and_records_the_admin(): void
    {
        $cashier = User::factory()->create(['role' => 'CASHIER']);
        $id = $this->qr('Q1', '30.00');
        DB::table('coupon_transactions')->insert([
            'coupon_id' => $id, 'coupon_number' => 'Q1', 'transaction_amount' => '20.00', 'coupon_type' => 'QRCODE', 'user_id' => $cashier->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $txId = DB::table('coupon_transactions')->value('transaction_id');
        $url = route('admin.coupon-transactions.reverse', $txId);

        $this->actingAs($this->admin('coupon_transactions.view'), 'admin')->post($url, ['reason' => 'Cobro duplicado'])->assertForbidden();

        $admin = $this->admin('coupon_transactions.view', 'coupon_transactions.reverse');
        $this->actingAs($admin, 'admin')->post($url, ['reason' => 'Cobro duplicado'])->assertSessionHas('status');
        $this->post($url, ['reason' => 'Cobro duplicado'])->assertSessionHasErrors('coupon'); // ya revertido

        $this->assertSame('50.00', $this->balance('coupons', $id));
        $this->assertDatabaseHas('coupon_transactions', ['reversed_of' => $txId, 'admin_user_id' => $admin->id, 'transaction_amount' => '-20.00']);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'reverse', 'admin_user_id' => $admin->id]);
    }

    public function test_row_actions_only_render_for_permitted_admins(): void
    {
        $this->qr('Q1');

        $this->actingAs($this->admin('coupons.view'), 'admin')->get(route('admin.screens.index', 'coupons'))
            ->assertOk()->assertDontSee('Ajustar saldo')->assertDontSee('Emitir cupones');

        $this->actingAs($this->admin('coupons.view', 'coupons.adjust', 'coupons.issue'), 'admin')->get(route('admin.screens.index', 'coupons'))
            ->assertOk()->assertSee('Ajustar saldo')->assertSee('Emitir cupones');
    }

    public function test_rbac_matrix_lists_coupon_actions_and_seeder_grants_them(): void
    {
        $this->assertContains('coupons.adjust', AdminRbac::allCrudPermissionNames());
        $this->assertContains('coupon_transactions.reverse', AdminRbac::allManagedPermissionNames());

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.rbac.matrix.edit', Role::findOrCreate('ops', 'admin')))
            ->assertOk()->assertSee('Ajustar saldo')->assertSee('Revertir');

        $this->seed(RbacSeeder::class);
        $manager = Role::findByName('ops-manager', 'admin');
        $this->assertTrue($manager->hasPermissionTo('coupons.issue'));
        $this->assertFalse($manager->hasPermissionTo('coupons.adjust'), 'ajustar saldo es solo super-admin');
        $this->assertFalse($manager->hasPermissionTo('coupon_transactions.reverse'));
        $this->assertTrue(Role::findByName('super-admin', 'admin')->hasPermissionTo('coupons.adjust'));
    }
}
