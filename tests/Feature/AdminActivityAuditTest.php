<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\CouponBatch;
use App\Models\CouponPrefix;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminActivityAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create(['name' => 'Ana Auditada']);
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
            $admin->givePermissionTo($p);
        }

        return $admin;
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('exports');
        parent::tearDown();
    }

    public function test_every_page_view_is_logged_with_user_route_and_summary(): void
    {
        $admin = $this->admin('customers.view');
        $this->actingAs($admin, 'admin')->get(route('admin.screens.index', ['customers', 'q' => 'Pérez']))->assertOk();

        $log = AdminAuditLog::where('action', 'view')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->admin_user_id);
        $this->assertSame('Ana Auditada', $log->admin_name);
        $this->assertSame('admin.screens.index', $log->route);
        $this->assertSame('GET', $log->method);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('Abrió la lista: Clientes (con filtros o búsqueda)', $log->summary);
        $this->assertSame('Pérez', $log->input['q']);
        $this->assertNotNull($log->created_at);
        $this->assertNotNull($log->ip);
    }

    public function test_forbidden_attempts_are_logged_too(): void
    {
        $this->actingAs($this->admin(), 'admin')->get(route('admin.screens.index', 'customers'))->assertForbidden();

        $this->assertTrue(AdminAuditLog::where('action', 'view')->where('status_code', 403)->exists());
    }

    public function test_submitted_actions_are_logged_without_secrets(): void
    {
        $admin = $this->admin('customers.view', 'customers.edit');
        $this->actingAs($admin, 'admin')->post(route('admin.screens.store', 'customers'), [
            'name' => 'Cliente Nuevo', 'phone' => '555', 'password' => 'Secreta-123!', 'api_token' => 'abc', 'pin' => '1234',
        ])->assertRedirect();

        $log = AdminAuditLog::where('action', 'submit')->where('route', 'admin.screens.store')->first();
        $this->assertNotNull($log);
        $this->assertSame('Creó un registro: Clientes', $log->summary);
        $this->assertSame('Cliente Nuevo', $log->input['name']);
        $this->assertSame('[oculto]', $log->input['password']);
        $this->assertSame('[oculto]', $log->input['api_token']);
        $this->assertSame('[oculto]', $log->input['pin']);
        $this->assertStringNotContainsString('Secreta-123!', json_encode($log->input));
        $this->assertArrayNotHasKey('_token', $log->input);
    }

    public function test_edits_keep_the_value_before_and_after(): void
    {
        $admin = $this->admin('customers.view', 'customers.edit');
        $customer = Customer::create(['name' => 'Nombre Viejo', 'phone' => '111']);
        $this->actingAs($admin, 'admin');
        AdminAuditLog::query()->delete();

        $this->put(route('admin.screens.update', ['customers', $customer->id]), ['name' => 'Nombre Nuevo', 'phone' => '111'])->assertRedirect();

        $log = AdminAuditLog::where('action', 'updated')->where('entity_type', 'Customer')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->admin_user_id);
        $this->assertSame(['Nombre Viejo', 'Nombre Nuevo'], $log->changes['name']);
        $this->assertArrayNotHasKey('phone', $log->changes, 'solo lo que cambió');
        $this->assertSame('Editó: Cliente «Nombre Nuevo»', $log->summary);
        $this->assertSame($customer->id, $log->entity_id);
        $this->assertSame('admin.screens.update', $log->route);
    }

    public function test_creations_and_deletions_keep_the_data(): void
    {
        $this->actingAs($this->admin('coupon_prefixes.view', 'coupon_prefixes.edit', 'coupon_prefixes.delete'), 'admin');

        $this->post(route('admin.screens.store', 'coupon-prefixes'), ['prefix' => 'xmas', 'description' => 'Navidad', 'is_active' => 1])->assertRedirect();
        $created = AdminAuditLog::where('action', 'created')->where('entity_type', 'CouponPrefix')->first();
        $this->assertSame([null, 'XMAS'], $created->changes['prefix']);
        $this->assertSame([null, 'Navidad'], $created->changes['description']);

        $prefix = CouponPrefix::first();
        $this->delete(route('admin.screens.destroy', ['coupon-prefixes', $prefix->id]));
        $deleted = AdminAuditLog::where('action', 'deleted')->where('entity_type', 'CouponPrefix')->first();
        $this->assertSame(['XMAS', null], $deleted->changes['prefix']);
        $this->assertSame('Eliminó: Prefijo de cupones «XMAS»', $deleted->summary);
    }

    public function test_generating_a_batch_records_the_click_and_the_data(): void
    {
        CouponPrefix::create(['prefix' => 'NAV']);
        $admin = $this->admin('coupon_batches.view', 'coupon_batches.generate');

        $this->actingAs($admin, 'admin')->post(route('admin.coupon-batches.store'), [
            'name' => 'Lote auditado', 'coupon_type' => 'CODBAR', 'quantity' => 3, 'amount' => 50, 'prefix' => 'NAV', 'notes' => 'Autorizado por gerencia',
        ])->assertRedirect();

        $click = AdminAuditLog::where('route', 'admin.coupon-batches.store')->where('action', 'submit')->first();
        $this->assertSame('Generó un lote de cupones', $click->summary);
        $this->assertSame('Autorizado por gerencia', $click->input['notes']);

        $created = AdminAuditLog::where('entity_type', 'CouponBatch')->where('action', 'created')->first();
        $this->assertEquals($admin->id, $created->admin_user_id);
        $this->assertSame([null, 'Lote auditado'], $created->changes['name']);
        $this->assertSame([null, 'NAV'], $created->changes['prefix']);
        $this->assertSame([null, 'Autorizado por gerencia'], $created->changes['notes']);
    }

    public function test_system_changes_by_jobs_or_the_api_do_not_pollute_the_log(): void
    {
        $batch = CouponBatch::create(['code' => 'L-1', 'name' => 'Sin admin', 'coupon_type' => 'CODBAR', 'quantity' => 1, 'amount' => '5.00', 'status' => 'generating', 'created_by_admin_id' => $this->admin()->id]);
        $batch->update(['status' => 'ready']);

        $this->assertSame(0, AdminAuditLog::where('entity_type', 'CouponBatch')->count());
    }

    public function test_secrets_never_reach_the_audit_log_and_polling_is_skipped(): void
    {
        $admin = $this->admin('customers.view');
        $this->actingAs($admin, 'admin');
        $this->get(route('admin.locations.map-status'));

        $this->assertSame(0, AdminAuditLog::where('route', 'admin.locations.map-status')->count());
    }

    public function test_the_audit_screen_filters_by_kind_and_user_and_shows_before_and_after(): void
    {
        $admin = $this->admin('audit_log.view', 'customers.view', 'customers.edit');
        $customer = Customer::create(['name' => 'Antes']);
        $this->actingAs($admin, 'admin');
        $this->put(route('admin.screens.update', ['customers', $customer->id]), ['name' => 'Después'])->assertRedirect();

        $html = $this->get(route('admin.audit.index'))->assertOk()->assertSee('Ana Auditada')->assertSee('Edición')->getContent();
        $this->assertStringContainsString('Antes', $html);
        $this->assertStringContainsString('Después', $html);
        $this->assertStringContainsString('admin.screens.update', $html);

        $changes = $this->get(route('admin.audit.index', ['kind' => 'changes']))->getContent();
        $this->assertStringContainsString('Editó: Cliente', $changes);
        $this->assertStringNotContainsString('Abrió la lista', $changes);

        $clicks = $this->get(route('admin.audit.index', ['kind' => 'clicks']))->getContent();
        $this->assertStringNotContainsString('Editó: Cliente', $clicks);

        $this->get(route('admin.audit.index', ['user' => AdminUser::factory()->create()->id]))->assertOk()->assertSee('No hay movimientos');
        $this->get(route('admin.audit.index', ['from' => '2030-02-01', 'to' => '2030-01-01']))->assertSessionHasErrors('to');
    }

    public function test_the_audit_screen_exports_csv_and_needs_permission(): void
    {
        $customer = Customer::create(['name' => 'Antes']);
        $admin = $this->admin('audit_log.view', 'customers.edit', 'customers.view');
        $this->actingAs($admin, 'admin')->put(route('admin.screens.update', ['customers', $customer->id]), ['name' => 'Después']);

        $csv = $this->get(route('admin.audit.index', ['export' => 'csv', 'kind' => 'changes']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Fecha y hora', $csv);
        $this->assertStringContainsString('name: Antes → Después', $csv);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.audit.index'))->assertForbidden();
    }
}
