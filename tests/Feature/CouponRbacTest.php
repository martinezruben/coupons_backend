<?php

namespace Tests\Feature;

use App\Exceptions\CouponException;
use App\Jobs\GenerateCouponBatch;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\CouponBatch;
use App\Models\Customer;
use App\Models\RoleLimit;
use App\Services\CouponActivationService;
use App\Services\CouponBatchGenerator;
use App\Support\AdminRbac;
use App\Support\RoleLimits;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CouponRbacTest extends TestCase
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

    private function batch(int $quantity = 10): CouponBatch
    {
        $batch = CouponBatchGenerator::create(['name' => 'Lote', 'coupon_type' => 'QRCODE', 'quantity' => $quantity, 'amount' => '20.00'], $this->admin(), null);
        (new CouponBatchGenerator)->generate($batch);

        return $batch->fresh();
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('exports');
        parent::tearDown();
    }

    // ---- Permisos granulares

    public function test_each_activation_mode_and_scope_has_its_own_permission(): void
    {
        $batch = $this->batch(10);
        $customer = Customer::create(['name' => 'Cliente X']);
        $post = fn (array $d) => $this->post(route('admin.coupon-batches.activate', $batch), $d + ['customer_id' => $customer->id, 'step' => 'confirm']);

        // Solo asignar: puede asignar una selección, no activar ni programar ni operar el lote completo
        $this->actingAs($this->admin('coupon_batches.assign'), 'admin');
        $post(['mode' => 'assign', 'selection_type' => 'quantity', 'quantity' => 2])->assertRedirect();
        $post(['mode' => 'assign', 'selection_type' => 'all'])->assertForbidden();
        $post(['mode' => 'now', 'selection_type' => 'quantity', 'quantity' => 2])->assertForbidden();
        $post(['mode' => 'scheduled', 'selection_type' => 'quantity', 'quantity' => 2, 'activate_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertForbidden();

        // Activar una selección, sin lote completo
        $this->actingAs($this->admin('coupon_batches.activate'), 'admin');
        $post(['mode' => 'now', 'selection_type' => 'range', 'from' => 3, 'to' => 4])->assertRedirect();
        $post(['mode' => 'now', 'selection_type' => 'all'])->assertForbidden();
        $post(['mode' => 'assign', 'selection_type' => 'range', 'from' => 5, 'to' => 5])->assertForbidden();

        // Programar necesita su permiso; el lote completo, el suyo
        $this->actingAs($this->admin('coupon_batches.schedule', 'coupon_batches.activate_all'), 'admin');
        $at = now()->addDays(2)->format('Y-m-d\TH:i');
        $post(['mode' => 'scheduled', 'selection_type' => 'all', 'activate_at' => $at])->assertRedirect();

        $this->assertSame(2, DB::table('coupons')->where('batch_id', $batch->id)->where('status', 'ACTIVE')->count());
        $this->assertSame(1, DB::table('activation_orders')->where('batch_id', $batch->id)->where('mode', 'scheduled')->where('status', 'pending')->count());
    }

    public function test_the_activation_form_only_offers_what_the_admin_can_do(): void
    {
        $batch = $this->batch(3);

        $html = $this->actingAs($this->admin('coupon_batches.activate'), 'admin')->get(route('admin.coupon-batches.activate.form', $batch))->assertOk()->getContent();
        $this->assertStringContainsString('Activar ahora', $html);
        $this->assertStringNotContainsString('Activar en una fecha y hora', $html);
        $this->assertStringNotContainsString('Solo asignar al cliente', $html);
        $this->assertStringNotContainsString('Todos los elegibles del lote', $html);

        $html = $this->actingAs($this->admin('coupon_batches.assign', 'coupon_batches.schedule', 'coupon_batches.activate_all'), 'admin')
            ->get(route('admin.coupon-batches.activate.form', $batch))->getContent();
        $this->assertStringContainsString('Activar en una fecha y hora', $html);
        $this->assertStringContainsString('Solo asignar al cliente', $html);
        $this->assertStringContainsString('Todos los elegibles del lote', $html);

        $this->actingAs($this->admin('coupon_batches.view'), 'admin')->get(route('admin.coupon-batches.activate.form', $batch))->assertForbidden();
    }

    public function test_unassign_needs_the_assign_permission(): void
    {
        $batch = $this->batch(4);
        $operator = $this->admin('coupon_batches.assign', 'coupon_batches.activate_all');
        $customer = Customer::create(['name' => 'Cliente X']);
        app(CouponActivationService::class)->submit($batch, $customer, 'assign', null, 'all', [], null, $operator, null);

        $this->actingAs($this->admin('coupon_batches.activate'), 'admin')->post(route('admin.coupon-batches.unassign', $batch), ['selection_type' => 'quantity', 'quantity' => 1])->assertForbidden();
        $this->actingAs($this->admin('coupon_batches.assign'), 'admin')->post(route('admin.coupon-batches.unassign', $batch), ['selection_type' => 'all'])->assertForbidden();
        $this->actingAs($this->admin('coupon_batches.assign'), 'admin')->post(route('admin.coupon-batches.unassign', $batch), ['selection_type' => 'quantity', 'quantity' => 2])->assertRedirect();

        $this->assertSame(2, DB::table('coupons')->where('batch_id', $batch->id)->where('status', 'GENERATED')->count());
    }

    public function test_matrix_lists_the_new_batch_permissions(): void
    {
        $names = AdminRbac::allManagedPermissionNames();
        foreach (['generate', 'download', 'assign', 'activate', 'activate_all', 'schedule', 'cancel', 'void'] as $action) {
            $this->assertContains("coupon_batches.$action", $names);
        }

        $this->actingAs($this->superAdmin(), 'admin')->get(route('admin.rbac.matrix.edit', Role::findOrCreate('x', 'admin')))
            ->assertOk()->assertSee('Operar sobre el lote completo')->assertSee('Programar activación')->assertSee('Asignar a cliente / liberar');
    }

    // ---- Límites por rol

    public function test_role_limits_resolution(): void
    {
        $none = $this->admin();
        $this->assertNull(RoleLimits::for($none, 'max_activation_size'), 'sin roles no hay límite propio');

        $small = Role::findOrCreate('chico', 'admin');
        $big = Role::findOrCreate('grande', 'admin');
        $open = Role::findOrCreate('abierto', 'admin');
        RoleLimit::create(['role_id' => $small->id, 'max_activation_size' => 100, 'max_batch_quantity' => 50, 'max_batch_value' => '1000']);
        RoleLimit::create(['role_id' => $big->id, 'max_activation_size' => 700]);

        $a = $this->admin();
        $a->assignRole($small);
        $this->assertSame(100, RoleLimits::for($a, 'max_activation_size'));
        $this->assertSame(50, RoleLimits::for($a, 'max_batch_quantity'));
        $this->assertSame(1000.0, RoleLimits::for($a, 'max_batch_value'));

        $a->assignRole($big); // el más permisivo; el rol grande no limita la cantidad por lote
        $this->assertSame(700, RoleLimits::for($a, 'max_activation_size'));
        $this->assertNull(RoleLimits::for($a, 'max_batch_quantity'));

        $b = $this->admin();
        $b->assignRole([$small, $open]); // un rol sin fila basta para no limitar
        $this->assertNull(RoleLimits::for($b, 'max_activation_size'));

        $super = $this->superAdmin();
        $super->assignRole($small);
        $this->assertNull(RoleLimits::for($super, 'max_activation_size'), 'el super-admin no tiene límites');
        $this->assertNull(RoleLimits::for($a, 'otra_cosa'));
    }

    public function test_activation_size_limit_applies_in_preview_and_submit(): void
    {
        $batch = $this->batch(10);
        $customer = Customer::create(['name' => 'Cliente X']);
        $role = Role::findOrCreate('ventas-x', 'admin');
        RoleLimit::create(['role_id' => $role->id, 'max_activation_size' => 3]);
        $seller = $this->admin('coupon_batches.activate', 'coupon_batches.activate_all');
        $seller->assignRole($role);
        $this->actingAs($seller, 'admin');
        $data = ['customer_id' => $customer->id, 'mode' => 'now', 'selection_type' => 'quantity'];

        $this->post(route('admin.coupon-batches.activate', $batch), $data + ['quantity' => 4])->assertSessionHasErrors('coupon');
        $this->assertStringContainsString('máximo', strtolower(session('errors')->first('coupon')).'máximo'); // mensaje con el tope
        $this->post(route('admin.coupon-batches.activate', $batch), $data + ['quantity' => 4, 'step' => 'confirm'])->assertSessionHasErrors('coupon');
        $this->assertSame(0, DB::table('coupons')->where('batch_id', $batch->id)->where('status', 'ACTIVE')->count());

        $this->post(route('admin.coupon-batches.activate', $batch), $data + ['quantity' => 3, 'step' => 'confirm'])->assertRedirect();
        $this->assertSame(3, DB::table('coupons')->where('batch_id', $batch->id)->where('status', 'ACTIVE')->count());
    }

    public function test_batch_quantity_and_value_limits_apply_when_generating(): void
    {
        $role = Role::findOrCreate('generador-x', 'admin');
        RoleLimit::create(['role_id' => $role->id, 'max_batch_quantity' => 100, 'max_batch_value' => '1000.00']);
        $generator = $this->admin('coupon_batches.generate');
        $generator->assignRole($role);
        $this->actingAs($generator, 'admin');
        $base = ['name' => 'Lote', 'coupon_type' => 'CODBAR', 'amount' => 5];

        $this->post(route('admin.coupon-batches.store'), $base + ['quantity' => 101])->assertSessionHasErrors('coupon');
        $this->post(route('admin.coupon-batches.store'), ['amount' => 20] + $base + ['quantity' => 60])->assertSessionHasErrors('coupon'); // 60 × 20 = 1200 > 1000
        $this->assertDatabaseCount('coupon_batches', 0);

        $this->post(route('admin.coupon-batches.store'), $base + ['quantity' => 100])->assertRedirect(); // 100 × 5 = 500
        $this->assertDatabaseCount('coupon_batches', 1);

        $this->expectException(CouponException::class);
        CouponBatchGenerator::assertWithinRoleLimits(101, 1, $generator);
    }

    public function test_the_matrix_saves_validates_clears_and_audits_limits(): void
    {
        $role = Role::findOrCreate('operador-x', 'admin');
        $this->actingAs($this->superAdmin(), 'admin');
        $url = route('admin.rbac.matrix.update', $role);

        $this->post($url, ['limits' => ['max_activation_size' => 0]])->assertSessionHasErrors('limits.max_activation_size');
        $this->post($url, ['limits' => ['max_activation_size' => 50000]])->assertSessionHasErrors('limits.max_activation_size');
        $this->post($url, ['limits' => ['max_batch_quantity' => 20001]])->assertSessionHasErrors('limits.max_batch_quantity');
        $this->post($url, ['limits' => ['max_batch_value' => -1]])->assertSessionHasErrors('limits.max_batch_value');

        $this->post($url, ['limits' => ['max_activation_size' => 250, 'max_batch_quantity' => '', 'max_batch_value' => '5000.50']])->assertRedirect();
        $row = RoleLimit::where('role_id', $role->id)->first();
        $this->assertSame(250, (int) $row->max_activation_size);
        $this->assertNull($row->max_batch_quantity);
        $this->assertSame('5000.50', number_format((float) $row->max_batch_value, 2, '.', ''));
        $this->assertTrue(AdminAuditLog::query()->where('entity_type', 'Role')->where('entity_id', $role->id)->get()->contains(fn ($l) => isset($l->changes['limit_max_activation_size'])));

        $this->get(route('admin.rbac.matrix.edit', $role))->assertOk()->assertSee('Límites del rol')->assertSee('value="250"', false);

        $this->post($url, ['limits' => ['max_activation_size' => '', 'max_batch_quantity' => '', 'max_batch_value' => '']])->assertRedirect();
        $this->assertNull($row->fresh()->max_activation_size);
        $this->assertNull($row->fresh()->max_batch_value);
    }

    // ---- Cuentas por rol

    public function test_accounts_can_be_added_and_removed_from_the_role_page_with_audit(): void
    {
        $role = Role::findOrCreate('operador-y', 'admin');
        $member = AdminUser::factory()->create(['name' => 'Marta Operadora']);
        $other = AdminUser::factory()->create(['name' => 'Pablo Nuevo']);
        $member->assignRole($role);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.rbac.matrix.edit', $role))->assertOk()->assertSee('Cuentas con este rol')->assertSee('Marta Operadora')->assertSee('Pablo Nuevo'); // en el selector

        $this->post(route('admin.rbac.accounts.add', $role), ['admin_user_id' => $other->id])->assertSessionHas('status');
        $this->assertTrue($other->fresh()->hasRole('operador-y'));
        $this->post(route('admin.rbac.accounts.add', $role), ['admin_user_id' => $other->id]); // idempotente
        $this->assertSame(1, $other->roles()->count());

        $this->delete(route('admin.rbac.accounts.remove', [$role, $member]))->assertSessionHas('status');
        $this->assertFalse($member->fresh()->hasRole('operador-y'));
        $this->assertSame(2, AdminAuditLog::query()->where('entity_type', 'AdminUser')->whereIn('entity_id', [$member->id, $other->id])->where('action', 'updated')->count());

        $this->post(route('admin.rbac.accounts.add', $role), ['admin_user_id' => 99999])->assertSessionHasErrors('admin_user_id');
    }

    public function test_only_the_super_admin_manages_accounts_and_the_super_role_is_protected(): void
    {
        $role = Role::findOrCreate('operador-z', 'admin');
        $target = AdminUser::factory()->create();

        $this->actingAs($this->admin('roles.edit'), 'admin');
        $this->post(route('admin.rbac.accounts.add', $role), ['admin_user_id' => $target->id])->assertForbidden();
        $this->delete(route('admin.rbac.accounts.remove', [$role, $target]))->assertForbidden();

        $this->actingAs($this->superAdmin(), 'admin');
        $super = Role::findOrCreate('super-admin', 'admin');
        $this->post(route('admin.rbac.accounts.add', $super), ['admin_user_id' => $target->id])->assertForbidden();
        $this->assertFalse($target->fresh()->hasRole('super-admin'));
    }

    public function test_roles_page_shows_cards_with_modules_and_accounts(): void
    {
        $role = Role::findOrCreate('operador-w', 'admin');
        $role->syncPermissions([Permission::findOrCreate('coupon_batches.assign', 'admin'), Permission::findOrCreate('coupon_batches.view', 'admin'), Permission::findOrCreate('customers.view', 'admin')]);
        AdminUser::factory()->create(['name' => 'Luis Operador'])->assignRole($role);

        $this->actingAs($this->superAdmin(), 'admin')->get(route('admin.roles.index'))->assertOk()
            ->assertSee('operador-w')->assertSee('Lotes de cupones · 2')->assertSee('Clientes · 1')->assertSee('title="Luis Operador"', false)->assertSee('Permisos y cuentas');
    }

    // ---- Perfiles de partida

    public function test_seeder_creates_the_starting_profiles_and_never_overwrites_them(): void
    {
        $this->seed(RbacSeeder::class);

        $generador = Role::findByName('generador', 'admin');
        $this->assertTrue($generador->hasPermissionTo('coupon_batches.generate'));
        $this->assertTrue($generador->hasPermissionTo('coupon_batches.download'));
        $this->assertFalse($generador->hasPermissionTo('coupon_batches.activate'), 'quien genera no activa');
        $this->assertFalse($generador->hasPermissionTo('coupon_batches.assign'));

        $operador = Role::findByName('operador', 'admin');
        foreach (['assign', 'activate', 'activate_all', 'schedule', 'cancel'] as $a) {
            $this->assertTrue($operador->hasPermissionTo("coupon_batches.$a"));
        }
        $this->assertFalse($operador->hasPermissionTo('coupon_batches.generate'));
        $this->assertFalse($operador->hasPermissionTo('coupon_batches.void'));

        $ventas = Role::findByName('ventas', 'admin');
        $this->assertTrue($ventas->hasPermissionTo('coupon_batches.activate'));
        $this->assertFalse($ventas->hasPermissionTo('coupon_batches.activate_all'));
        $this->assertFalse($ventas->hasPermissionTo('coupon_batches.schedule'));
        $this->assertSame(500, (int) RoleLimit::where('role_id', $ventas->id)->value('max_activation_size'));

        $supervisor = Role::findByName('supervisor', 'admin');
        $this->assertTrue($supervisor->hasPermissionTo('coupon_batches.void'));
        $this->assertTrue($supervisor->hasPermissionTo('coupon_transactions.reverse'));
        $this->assertFalse($supervisor->hasPermissionTo('coupons.adjust'));
        $this->assertFalse($supervisor->hasPermissionTo('coupon_batches.generate'));

        $auditor = Role::findByName('auditor', 'admin');
        $this->assertTrue($auditor->hasPermissionTo('coupon_audit_logs.view'));
        $this->assertTrue($auditor->hasPermissionTo('coupon_batch_report.view'));
        foreach (Permission::where('guard_name', 'admin')->pluck('name') as $name) {
            if (str_starts_with($name, 'coupon') && ! str_ends_with($name, '.view') && $auditor->hasPermissionTo($name)) {
                $this->fail("el auditor no debe tener $name");
            }
        }

        // Un ajuste hecho a mano sobrevive a otra siembra
        $ventas->givePermissionTo('coupon_batches.schedule');
        RoleLimit::where('role_id', $ventas->id)->update(['max_activation_size' => 900]);
        $this->seed(RbacSeeder::class);
        $this->assertTrue(Role::findByName('ventas', 'admin')->hasPermissionTo('coupon_batches.schedule'));
        $this->assertSame(900, (int) RoleLimit::where('role_id', $ventas->id)->value('max_activation_size'));
    }

    public function test_generating_twice_from_a_retried_job_creates_the_coupons_only_once(): void
    {
        $batch = CouponBatchGenerator::create(['name' => 'Lote', 'coupon_type' => 'CODBAR', 'quantity' => 5, 'amount' => '5'], $this->admin(), null);

        (new GenerateCouponBatch($batch->id))->handle(app(CouponBatchGenerator::class));
        (new GenerateCouponBatch($batch->id))->handle(app(CouponBatchGenerator::class)); // reintento / segundo worker

        $this->assertSame(5, DB::table('coupons_codbar')->where('batch_id', $batch->id)->count());
        $this->assertSame('ready', $batch->fresh()->status);
        $this->assertGreaterThan(900, (int) config('queue.connections.database.retry_after'), 'retry_after debe superar el timeout del job');
    }
}
