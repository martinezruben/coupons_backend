<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IndependentPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): AdminUser
    {
        $a = AdminUser::factory()->create();
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
            $a->givePermissionTo($p);
        }

        return $a;
    }

    private function super(): AdminUser
    {
        $a = AdminUser::factory()->create(['name' => 'Sara Super']);
        $a->assignRole(Role::findOrCreate('super-admin', 'admin'));

        return $a;
    }

    private function coupon(): int
    {
        return DB::table('coupons_codbar')->insertGetId(['coupon_number' => 'B1', 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => '30.00', 'coupon_balance' => '30.00']);
    }

    public function test_adjust_balance_is_granted_alone_to_one_user(): void
    {
        $id = $this->coupon();
        $target = AdminUser::factory()->create(['name' => 'Tomás']);
        $url = route('admin.coupons.adjust', ['barcode', $id]);

        $this->actingAs($target, 'admin')->post($url, ['balance' => 10, 'reason' => 'Corrección'])->assertForbidden();

        $this->actingAs($this->super(), 'admin')->post(route('admin.rbac.users.update', $target), ['permissions' => ['coupon_barcodes.adjust']])->assertRedirect();

        // Solo ese permiso: no puede editar nada más ni ver otras pantallas, pero sí ajustar el saldo
        $this->assertSame(['coupon_barcodes.adjust'], $target->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->actingAs($target->fresh(), 'admin')->post($url, ['balance' => 10, 'reason' => 'Corrección'])->assertRedirect();
        $this->assertSame('10.00', number_format((float) DB::table('coupons_codbar')->value('coupon_balance'), 2, '.', ''));
        $this->get(route('admin.screens.index', 'customers'))->assertForbidden();
    }

    public function test_the_user_screen_lists_independent_permissions_with_sensitive_marks(): void
    {
        $target = AdminUser::factory()->create();
        $html = $this->actingAs($this->super(), 'admin')->get(route('admin.rbac.users.edit', $target))->assertOk()->assertSee('Permisos independientes')->getContent();

        foreach (['coupons.adjust', 'coupon_barcodes.adjust', 'coupons.topup', 'coupon_transactions.reverse', 'coupon_batches.void', 'coupons.detail', 'audit_log.export', 'coupon_reports.export'] as $p) {
            $this->assertStringContainsString('value="'.$p.'"', $html, "falta $p");
        }
        $this->assertStringContainsString('sensible', $html);
        $this->assertStringNotContainsString('<th class="px-2 py-1.5 font-semibold">Acciones</th>', $html);
    }

    public function test_the_coupon_history_needs_its_own_permission(): void
    {
        $id = $this->coupon();
        $viewer = $this->admin('coupon_barcodes.view');
        $this->actingAs($viewer, 'admin')->get(route('admin.coupons.show', ['barcode', $id]))->assertForbidden();

        $list = $this->get(route('admin.coupons.index'))->assertOk()->getContent();
        $this->assertStringContainsString('B1', $list);
        $this->assertStringNotContainsString(route('admin.coupons.show', ['barcode', $id]), $list, 'sin permiso no hay enlace al historial');

        $this->actingAs($this->admin('coupon_barcodes.view', 'coupon_barcodes.detail'), 'admin')->get(route('admin.coupons.show', ['barcode', $id]))->assertOk();
        $this->get(route('admin.coupons.index'))->assertSee(route('admin.coupons.show', ['barcode', $id]), false);
    }

    public function test_csv_exports_need_their_own_permission(): void
    {
        $cases = [
            ['audit_log', route('admin.audit.index', ['export' => 'csv'])],
            ['coupon_reports', route('admin.reports.redemptions', ['export' => 'csv'])],
            ['coupon_batch_report', route('admin.reports.batches', ['export' => 'csv'])],
            ['coupon_customer_report', route('admin.reports.customers', ['export' => 'csv'])],
        ];
        foreach ($cases as [$resource, $csv]) {
            $this->actingAs($this->admin("$resource.view"), 'admin')->get($csv)->assertForbidden();
            $page = explode('?', $csv)[0];
            $this->assertDoesNotMatchRegularExpression('/>(Exportar )?CSV<\/a>/', $this->get($page)->assertOk()->getContent(), $resource);

            $this->actingAs($this->admin("$resource.view", "$resource.export"), 'admin')->get($csv)->assertOk();
            $this->assertMatchesRegularExpression('/>(Exportar )?CSV<\/a>/', $this->get($page)->assertOk()->getContent(), $resource);
        }
    }

    public function test_the_migration_keeps_access_for_who_could_already_view(): void
    {
        $role = Role::findOrCreate('lectura', 'admin');
        Permission::findOrCreate('coupons.view', 'admin');
        Permission::findOrCreate('audit_log.view', 'admin');
        $role->givePermissionTo(['coupons.view']);
        $direct = $this->admin('audit_log.view');
        $nobody = $this->admin('customers.view');

        (require database_path('migrations/2026_11_20_000001_add_independent_permissions.php'))->up();
        (require database_path('migrations/2026_11_20_000001_add_independent_permissions.php'))->up(); // idempotente

        $this->assertTrue($role->fresh()->hasPermissionTo('coupons.detail'));
        $this->assertTrue($direct->fresh()->hasPermissionTo('audit_log.export'));
        $this->assertFalse($nobody->fresh()->can('audit_log.export'));
        $this->assertFalse($role->fresh()->permissions->contains('name', 'coupon_reports.export'));
    }

    public function test_the_seeder_profiles_get_the_companion_permissions(): void
    {
        $this->seed(RbacSeeder::class);

        $this->assertTrue(Role::findByName('auditor', 'admin')->hasPermissionTo('audit_log.export'));
        $this->assertTrue(Role::findByName('operador', 'admin')->hasPermissionTo('coupons.detail'));
        $this->assertTrue(Role::findByName('ops-viewer', 'admin')->hasPermissionTo('coupons.detail'));
        $this->assertFalse(Role::findByName('generador', 'admin')->hasPermissionTo('coupons.adjust'));
    }
}
