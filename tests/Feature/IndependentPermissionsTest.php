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

    public function test_activate_and_deactivate_are_separate_permissions_and_buttons(): void
    {
        $id = $this->coupon(); // ACTIVE
        $url = route('admin.coupons.toggle', ['barcode', $id]);
        $detail = route('admin.coupons.show', ['barcode', $id]);

        // Solo activar: no puede desactivar y el botón no aparece
        $onlyActivate = $this->admin('coupon_barcodes.view', 'coupon_barcodes.detail', 'coupon_barcodes.activate');
        $this->actingAs($onlyActivate, 'admin')->post($url, ['active' => 0, 'reason' => 'Fraude'])->assertForbidden();
        $this->get($detail)->assertOk()->assertDontSee('Desactivar')->assertDontSee('Ajustar saldo')->assertDontSee('Recargar');

        // Solo desactivar: el botón aparece y funciona; activar sigue prohibido
        $onlyDeactivate = $this->admin('coupon_barcodes.view', 'coupon_barcodes.detail', 'coupon_barcodes.deactivate');
        $this->actingAs($onlyDeactivate, 'admin')->get($detail)->assertOk()->assertSee('Desactivar');
        $this->post($url, ['active' => 0, 'reason' => 'Fraude'])->assertRedirect();
        $this->assertSame('INACTIVE', DB::table('coupons_codbar')->where('coupon_id', $id)->value('status'));
        $this->post($url, ['active' => 1, 'reason' => 'Error'])->assertForbidden();
        $this->get($detail)->assertOk()->assertDontSee('>Activar<', false);

        $this->actingAs($onlyActivate->fresh(), 'admin')->get($detail)->assertOk()->assertSee('Activar');
    }

    public function test_the_user_screen_groups_the_coupon_buttons_one_by_one(): void
    {
        $target = AdminUser::factory()->create();
        $html = $this->actingAs($this->super(), 'admin')->get(route('admin.rbac.users.edit', $target))->assertOk()->assertSee('Botones del detalle de un cupón')->getContent();

        foreach (['Desactivar cupón', 'Activar cupón', 'Recargar cupón', 'Ajustar saldo del cupón', 'Cupón QR', 'Cupón de barras'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['coupons', 'coupon_barcodes'] as $r) {
            foreach (['activate', 'deactivate', 'topup', 'adjust', 'detail'] as $a) {
                $this->assertStringContainsString('value="'.$r.'.'.$a.'"', $html);
            }
        }
        $this->assertStringNotContainsString('value="coupons.toggle"', $html);
        $this->assertLessThan(strpos($html, 'Pantalla</th>') ?: strpos($html, 'Pantalla'), strpos($html, 'Botones del detalle de un cupón'));
    }

    public function test_the_migration_splits_the_old_toggle_permission(): void
    {
        $role = Role::findOrCreate('gestor', 'admin');
        Permission::findOrCreate('coupons.toggle', 'admin');
        $role->givePermissionTo('coupons.toggle');
        $direct = $this->admin('coupons.toggle');
        $none = $this->admin('coupons.view');

        (require database_path('migrations/2026_11_22_000001_split_coupon_toggle_permission.php'))->up();
        (require database_path('migrations/2026_11_22_000001_split_coupon_toggle_permission.php'))->up();

        $names = $role->fresh()->permissions->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['coupons.activate', 'coupons.deactivate'], $names);
        $this->assertEqualsCanonicalizing(['coupons.activate', 'coupons.deactivate'], $direct->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->assertSame([], $none->fresh()->getDirectPermissions()->pluck('name')->diff(['coupons.view'])->all());
        $this->assertDatabaseMissing('permissions', ['name' => 'coupons.toggle']);
    }
}
