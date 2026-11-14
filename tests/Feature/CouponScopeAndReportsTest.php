<?php

namespace Tests\Feature;

use App\Models\ActivationOrder;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\CouponBatch;
use App\Models\Customer;
use App\Services\CouponActivationService;
use App\Services\CouponBatchGenerator;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CouponScopeAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private CouponBatch $batch;

    private Customer $visible;

    private Customer $hidden;

    private AdminUser $restricted;

    private AdminUser $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = AdminUser::factory()->create();
        $this->super->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $this->batch = CouponBatchGenerator::create(['name' => 'Lote A', 'coupon_type' => 'CODBAR', 'quantity' => 8, 'amount' => '10.00'], AdminUser::factory()->create(), null);
        (new CouponBatchGenerator)->generate($this->batch);
        $this->batch = $this->batch->fresh();

        $this->visible = Customer::create(['name' => 'Cliente Visible']);
        $this->hidden = Customer::create(['name' => 'Cliente Oculto']);

        $svc = app(CouponActivationService::class);
        $svc->submit($this->batch, $this->visible, 'now', null, 'quantity', ['quantity' => 3], null, $this->super, null);
        $svc->submit($this->batch, $this->hidden, 'now', null, 'quantity', ['quantity' => 2], null, $this->super, null);
        $svc->submit($this->batch, $this->hidden, 'scheduled', now()->addDay(), 'quantity', ['quantity' => 1], null, $this->super, null);

        $this->restricted = AdminUser::factory()->create(['customer_scope' => 'selected']);
        $this->restricted->customers()->sync([$this->visible->id]);
        foreach (['customers.view', 'customers.edit', 'coupons.view', 'coupons.toggle', 'coupon_batches.view', 'coupon_batches.assign', 'coupon_batches.activate', 'coupon_batches.activate_all', 'coupon_batches.cancel', 'coupon_batch_report.view', 'coupon_customer_report.view', 'coupon_reports.view'] as $p) {
            Permission::findOrCreate($p, 'admin');
            $this->restricted->givePermissionTo($p);
        }
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('exports');
        parent::tearDown();
    }

    private function redeem(Customer $customer, string $amount): void
    {
        $number = DB::table('coupons_codbar')->where('customer_id', $customer->id)->where('status', 'ACTIVE')->value('coupon_number');
        CouponService::barcode()->redeem([$number], null, $amount, null, null, null);
    }

    public function test_scope_helpers(): void
    {
        $this->assertNull($this->super->allowedCustomerIds());
        $this->assertFalse($this->super->isCustomerRestricted());
        $this->assertSame([$this->visible->id], $this->restricted->allowedCustomerIds());
        $this->assertTrue($this->restricted->isCustomerRestricted());
        $this->assertNull(AdminUser::factory()->create()->allowedCustomerIds(), 'por defecto, todos los clientes');

        $none = AdminUser::factory()->create(['customer_scope' => 'selected']);
        $this->assertSame([], $none->allowedCustomerIds(), 'alcance vacío no ve nada, no todo');
        $this->assertSame(0, Customer::query()->visibleTo($none)->count());
        $this->assertSame(2, Customer::query()->visibleTo($this->super)->count());
    }

    public function test_lists_only_show_the_customers_in_scope(): void
    {
        $this->actingAs($this->restricted, 'admin');
        $this->get(route('admin.screens.index', 'customers'))->assertOk()->assertSee('Cliente Visible')->assertDontSee('Cliente Oculto');

        $this->assertSame(3, BarcodeCoupon::query()->visibleTo($this->restricted)->where('status', 'ACTIVE')->count());
        $this->assertSame(0, BarcodeCoupon::query()->visibleTo($this->restricted)->where('customer_id', $this->hidden->id)->count());
        $this->assertSame(1, ActivationOrder::query()->visibleTo($this->restricted)->count());
        $this->assertSame(0, ActivationOrder::query()->visibleTo($this->restricted)->where('status', 'pending')->count());

        $this->actingAs($this->super, 'admin')->get(route('admin.screens.index', 'customers'))->assertSee('Cliente Visible')->assertSee('Cliente Oculto');
    }

    public function test_records_outside_the_scope_are_not_found(): void
    {
        $this->actingAs($this->restricted, 'admin');
        $this->get(route('admin.screens.edit', ['customers', $this->hidden->id]))->assertNotFound();
        $this->get(route('admin.screens.edit', ['customers', $this->visible->id]))->assertOk();

        $coupon = DB::table('coupons_codbar')->where('customer_id', $this->hidden->id)->first();
        $this->post(route('admin.coupons.toggle', ['CODBAR', $coupon->coupon_id]))->assertNotFound();
        $this->assertSame('ACTIVE', DB::table('coupons_codbar')->where('coupon_id', $coupon->coupon_id)->value('status') === 'ACTIVE' ? 'ACTIVE' : 'otro');

        $pending = ActivationOrder::where('customer_id', $this->hidden->id)->where('status', 'pending')->first();
        $this->post(route('admin.activation-orders.cancel', $pending))->assertNotFound();
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_a_restricted_admin_cannot_activate_or_assign_for_other_customers(): void
    {
        $this->actingAs($this->restricted, 'admin');
        $payload = ['mode' => 'assign', 'selection_type' => 'quantity', 'quantity' => 1, 'step' => 'confirm'];

        $this->post(route('admin.coupon-batches.activate', $this->batch), $payload + ['customer_id' => $this->hidden->id])->assertSessionHasErrors();
        $this->assertSame(2, DB::table('coupons_codbar')->where('batch_id', $this->batch->id)->where('status', 'GENERATED')->count());

        $this->post(route('admin.coupon-batches.activate', $this->batch), $payload + ['customer_id' => $this->visible->id])->assertRedirect();
        $this->assertSame(4, DB::table('coupons_codbar')->where('customer_id', $this->visible->id)->count());

        $form = $this->get(route('admin.coupon-batches.activate.form', $this->batch))->assertOk()->getContent();
        $this->assertStringContainsString('Cliente Visible', $form);
        $this->assertStringNotContainsString('Cliente Oculto', $form);
    }

    public function test_unassign_never_touches_coupons_of_other_customers(): void
    {
        $svc = app(CouponActivationService::class);
        $svc->submit($this->batch, $this->visible, 'assign', null, 'quantity', ['quantity' => 1], null, $this->super, null);
        $svc->submit($this->batch, $this->hidden, 'assign', null, 'quantity', ['quantity' => 1], null, $this->super, null);

        $n = $svc->unassign($this->batch, 'all', [], null, $this->restricted, null);

        $this->assertSame(1, $n);
        $this->assertSame(2, DB::table('coupons_codbar')->where('customer_id', $this->hidden->id)->where('status', 'ASSIGNED')->count());
    }

    public function test_the_batch_page_hides_other_customers_funnel_and_orders(): void
    {
        $html = $this->actingAs($this->restricted, 'admin')->get(route('admin.coupon-batches.show', $this->batch))->assertOk()->getContent();
        $this->assertStringContainsString('Cliente Visible', $html);
        $this->assertStringNotContainsString('Cliente Oculto', $html);

        $this->actingAs($this->super, 'admin')->get(route('admin.coupon-batches.show', $this->batch))->assertOk()->assertSee('Cliente Oculto');
    }

    public function test_the_admin_user_form_saves_the_scope_with_validation_and_audit(): void
    {
        $target = AdminUser::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $this->actingAs($this->super, 'admin');
        $base = ['name' => 'Ana', 'email' => 'ana@example.com', 'is_active' => 1];
        $url = route('admin.screens.update', ['admin-users', $target->id]);

        $this->put($url, $base + ['customer_scope' => 'selected', 'customer_ids' => []])->assertSessionHasErrors('customer_ids');
        $this->put($url, $base + ['customer_scope' => 'selected', 'customer_ids' => ['no-es-uuid']])->assertSessionHasErrors('customer_ids.0');
        $this->put($url, $base + ['customer_scope' => 'otro'])->assertSessionHasErrors('customer_scope');

        $this->put($url, $base + ['customer_scope' => 'selected', 'customer_ids' => [$this->visible->id]])->assertSessionHasNoErrors();
        $this->assertSame('selected', $target->fresh()->customer_scope);
        $this->assertSame([$this->visible->id], $target->fresh()->allowedCustomerIds());
        $this->assertTrue(AdminAuditLog::query()->where('entity_id', $target->id)->get()->contains(fn ($l) => isset($l->changes['customer_scope'])));

        $this->put($url, $base + ['customer_scope' => 'all'])->assertSessionHasNoErrors();
        $this->assertNull($target->fresh()->allowedCustomerIds());
        $this->assertSame(0, DB::table('admin_user_customers')->where('admin_user_id', $target->id)->count());

        $this->get(route('admin.screens.edit', ['admin-users', $target->id]))->assertOk()->assertSee('Alcance por cliente');
    }

    // ---- Dashboard y reportes

    public function test_the_dashboard_shows_kpis_and_respects_the_scope_and_filters(): void
    {
        $this->redeem($this->visible, '4.00');
        $this->redeem($this->hidden, '6.00');

        $html = $this->actingAs($this->super, 'admin')->get(route('admin.dashboard'))->assertOk()->assertSee('Saldo en circulación')->assertSee('Activaciones programadas')->getContent();
        $this->assertStringContainsString('Cliente Oculto', $html);

        $this->get(route('admin.dashboard', ['days' => 30, 'customer' => $this->hidden->id]))->assertOk();
        $this->get(route('admin.dashboard', ['days' => 5]))->assertSessionHasErrors('days');
        $this->get(route('admin.dashboard', ['customer' => 'x']))->assertSessionHasErrors('customer');

        $r = $this->actingAs($this->restricted, 'admin');
        $html = $r->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Cliente Oculto', $html);
        $r->get(route('admin.dashboard', ['customer' => $this->hidden->id]))->assertNotFound();
        $r->get(route('admin.dashboard', ['customer' => $this->visible->id]))->assertOk();
    }

    public function test_the_batch_report_counts_by_status_and_exports_csv(): void
    {
        $this->redeem($this->visible, '4.00');

        $this->actingAs($this->super, 'admin')->get(route('admin.reports.batches'))->assertOk()->assertSee('Lote A');

        $csv = $this->get(route('admin.reports.batches', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $row = str_getcsv($lines[1]);
        $this->assertSame('Lote A', $row[1]);
        $this->assertSame('8', $row[5]);
        $this->assertSame('2', $row[6]);   // sin asignar: 8 − 3 − 2 − 1
        $this->assertSame('5', $row[8]);   // activos (3 + 2), la programada aún no
        $this->assertSame('4.00', $row[12]);

        $this->get(route('admin.reports.batches', ['status' => 'void']))->assertOk()->assertDontSee('Lote A');
        $this->get(route('admin.reports.batches', ['from' => '2030-02-01', 'to' => '2030-01-01']))->assertSessionHasErrors('to');
    }

    public function test_reports_are_scoped_and_permission_protected(): void
    {
        $this->redeem($this->visible, '4.00');
        $this->redeem($this->hidden, '6.00');

        $csv = $this->actingAs($this->restricted, 'admin')->get(route('admin.reports.batches', ['export' => 'csv']))->streamedContent();
        $row = str_getcsv(array_values(array_filter(explode("\n", trim($csv))))[1]);
        $this->assertSame('3', $row[8], 'solo ve los activos de su cliente');
        $this->assertSame('4.00', $row[12]);

        $html = $this->get(route('admin.reports.customers'))->assertOk()->getContent();
        $this->assertStringContainsString('Cliente Visible', $html);
        $this->assertStringNotContainsString('Cliente Oculto', $html);

        $nobody = AdminUser::factory()->create();
        $this->actingAs($nobody, 'admin');
        $this->get(route('admin.reports.batches'))->assertForbidden();
        $this->get(route('admin.reports.customers'))->assertForbidden();
    }

    public function test_the_customer_report_exports_csv_and_neutralizes_formulas(): void
    {
        Customer::create(['name' => '=HYPERLINK("http://x")', 'document' => '+123']);
        $this->redeem($this->visible, '4.00');

        $csv = $this->actingAs($this->super, 'admin')->get(route('admin.reports.customers', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString('Cliente Visible', $csv);

        $this->get(route('admin.reports.customers', ['q' => 'Oculto']))->assertOk()->assertSee('Cliente Oculto')->assertDontSee('Cliente Visible');
    }
}
