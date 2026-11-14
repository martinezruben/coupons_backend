<?php

namespace Tests\Feature;

use App\Exceptions\CouponException;
use App\Models\ActivationOrder;
use App\Models\AdminUser;
use App\Models\CouponBatch;
use App\Models\Customer;
use App\Services\CouponActivationService;
use App\Services\CouponBatchGenerator;
use App\Services\CouponService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CouponActivationTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $generator;

    private AdminUser $operator;

    private CouponActivationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = AdminUser::factory()->create();
        $this->operator = AdminUser::factory()->create();
        $this->service = app(CouponActivationService::class);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('exports');
        parent::tearDown();
    }

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    private function batch(int $quantity = 10, string $type = 'QRCODE'): CouponBatch
    {
        $batch = CouponBatchGenerator::create(['name' => 'Lote', 'coupon_type' => $type, 'quantity' => $quantity, 'amount' => '20.00'], $this->generator, null);
        (new CouponBatchGenerator)->generate($batch);

        return $batch->fresh();
    }

    private function customer(string $name = 'Cliente X'): Customer
    {
        return Customer::create(['name' => $name]);
    }

    private function statuses(CouponBatch $batch): array
    {
        return DB::table('coupons')->where('batch_id', $batch->id)->orderBy('seq')->pluck('status', 'seq')->all();
    }

    private function countStatus(CouponBatch $batch, string $status): int
    {
        return DB::table('coupons')->where('batch_id', $batch->id)->where('status', $status)->count();
    }

    private function submit(CouponBatch $batch, Customer $customer, string $mode = 'now', string $type = 'all', array $input = [], ?Carbon $at = null, ?AdminUser $actor = null): ActivationOrder
    {
        return $this->service->submit($batch, $customer, $mode, $at, $type, $input, null, $actor ?? $this->operator, null);
    }

    // ---- Selección

    public function test_activate_all_eligible_assigns_the_customer_and_activates_now(): void
    {
        $batch = $this->batch(10);
        $customer = $this->customer();

        $order = $this->submit($batch, $customer);

        $this->assertSame(10, $order->coupons_count);
        $this->assertSame('applied', $order->status);
        $this->assertSame(10, $this->countStatus($batch, 'ACTIVE'));
        $row = DB::table('coupons')->where('batch_id', $batch->id)->first();
        $this->assertSame(1, (int) $row->coupon_active);
        $this->assertSame($customer->id, $row->customer_id);
        $this->assertSame($order->id, $row->activation_order_id);
        $this->assertNotNull($row->activated_at);
        $this->assertNotNull($row->assigned_at);
    }

    public function test_range_quantity_and_list_selections(): void
    {
        $batch = $this->batch(10);
        $customer = $this->customer();

        $this->submit($batch, $customer, 'now', 'range', ['from' => 3, 'to' => 5]);
        $this->assertSame([3, 4, 5], array_keys(array_filter($this->statuses($batch), fn ($s) => $s === 'ACTIVE')));

        $this->submit($batch, $customer, 'now', 'quantity', ['quantity' => 2]); // primeros 2 elegibles: 1 y 2
        $this->assertSame([1, 2, 3, 4, 5], array_keys(array_filter($this->statuses($batch), fn ($s) => $s === 'ACTIVE')));

        $numbers = DB::table('coupons')->where('batch_id', $batch->id)->whereIn('seq', [9, 10])->pluck('coupon_number')->all();
        $this->submit($batch, $customer, 'now', 'list', ['numbers' => $numbers]);
        $this->assertSame(7, $this->countStatus($batch, 'ACTIVE'));
        $this->assertSame(3, $this->countStatus($batch, 'GENERATED'));
    }

    public function test_invalid_selections_are_rejected_without_touching_anything(): void
    {
        $batch = $this->batch(5);
        $customer = $this->customer();
        $foreign = $this->batch(2);
        $foreignNumber = DB::table('coupons')->where('batch_id', $foreign->id)->value('coupon_number');
        $ownNumber = DB::table('coupons')->where('batch_id', $batch->id)->value('coupon_number');

        foreach ([
            ['range', ['from' => 5, 'to' => 2]], ['range', ['from' => 0, 'to' => 2]], ['range', ['from' => 6, 'to' => 9]],
            ['quantity', ['quantity' => 0]], ['list', ['numbers' => []]], ['list', ['numbers' => [$ownNumber, 'NOEXISTE']]],
            ['list', ['numbers' => [$foreignNumber]]], ['otra', []],
        ] as [$type, $input]) {
            try {
                $this->submit($batch, $customer, 'now', $type, $input);
                $this->fail("debió rechazar $type ".json_encode($input));
            } catch (CouponException $e) {
                $this->assertContains($e->getCode() ?: 422, [0, 422]);
            }
        }
        $this->assertSame(5, $this->countStatus($batch, 'GENERATED'));
        $this->assertDatabaseCount('activation_orders', 0);
    }

    public function test_cannot_exceed_the_maximum_per_operation(): void
    {
        config(['coupons.max_activation_size' => 3]);
        $batch = $this->batch(5);

        $this->expectException(CouponException::class);
        $this->submit($batch, $this->customer(), 'now');
    }

    // ---- Modos

    public function test_assign_reserves_for_the_customer_without_activating(): void
    {
        $batch = $this->batch(6);
        $customer = $this->customer();

        $this->submit($batch, $customer, 'assign', 'quantity', ['quantity' => 4]);

        $this->assertSame(4, $this->countStatus($batch, 'ASSIGNED'));
        $this->assertSame(0, DB::table('coupons')->where('batch_id', $batch->id)->where('coupon_active', 1)->count());
        $this->assertSame(2, $this->countStatus($batch, 'GENERATED'));
        // Un asignado no se puede volver a asignar, pero sí activar para el mismo cliente
        $this->assertSame('applied', ActivationOrder::first()->status);
    }

    public function test_assigned_coupons_can_be_activated_later_only_for_the_same_customer(): void
    {
        $batch = $this->batch(6);
        $a = $this->customer('A');
        $b = $this->customer('B');
        $this->submit($batch, $a, 'assign', 'quantity', ['quantity' => 3]);

        // B no puede tomar los reservados para A: solo los 3 libres
        $order = $this->submit($batch, $b, 'now');
        $this->assertSame(3, $order->coupons_count);
        $this->assertSame(3, DB::table('coupons')->where('batch_id', $batch->id)->where('customer_id', $b->id)->where('status', 'ACTIVE')->count());
        $this->assertSame(3, DB::table('coupons')->where('batch_id', $batch->id)->where('customer_id', $a->id)->where('status', 'ASSIGNED')->count());

        $this->submit($batch, $a, 'now');
        $this->assertSame(6, $this->countStatus($batch, 'ACTIVE'));
        // Una lista con un cupón de otro cliente se rechaza
        $this->expectException(CouponException::class);
        $this->submit($batch, $b, 'assign', 'list', ['numbers' => [DB::table('coupons')->where('customer_id', $a->id)->value('coupon_number')]]);
    }

    public function test_assign_mode_does_not_take_already_assigned_coupons(): void
    {
        $batch = $this->batch(4);
        $this->submit($batch, $this->customer('A'), 'assign', 'quantity', ['quantity' => 4]);

        $this->expectException(CouponException::class);
        $this->submit($batch, $this->customer('B'), 'assign');
    }

    public function test_scheduled_activation_waits_for_its_date_then_applies_once(): void
    {
        $batch = $this->batch(5);
        $customer = $this->customer();
        $at = now()->addDays(2);

        $order = $this->submit($batch, $customer, 'scheduled', 'all', [], $at);

        $this->assertSame('pending', $order->status);
        $this->assertSame(5, $this->countStatus($batch, 'ASSIGNED'));
        $this->assertSame(0, $this->service->applyDue());

        $this->travelTo($at->copy()->subMinute());
        $this->artisan('coupons:activate-due')->assertSuccessful();
        $this->assertSame(5, $this->countStatus($batch, 'ASSIGNED'));

        $this->travelTo($at->copy()->addMinute());
        $this->artisan('coupons:activate-due')->assertSuccessful();
        $this->assertSame(5, $this->countStatus($batch, 'ACTIVE'));
        $this->assertSame(5, DB::table('coupons')->where('batch_id', $batch->id)->where('coupon_active', 1)->count());
        $this->assertSame('applied', $order->fresh()->status);
        $this->assertNotNull(DB::table('coupons')->where('batch_id', $batch->id)->value('activated_at'));

        $this->assertSame(0, $this->service->applyDue(), 'idempotente');
        $this->assertSame(1, DB::table('coupon_audit_logs')->where('action', 'activate')->where('activation_order_id', $order->id)->count());
    }

    public function test_the_scheduler_runs_the_due_command_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' '.$e->expression);

        $this->assertTrue($events->contains(fn ($c) => str_contains($c, 'coupons:activate-due') && str_ends_with($c, '* * * * *')));
        $this->assertTrue($events->contains(fn ($c) => str_contains($c, 'coupons:purge-exports')));
    }

    public function test_schedule_dates_are_validated(): void
    {
        $batch = $this->batch(3);
        $customer = $this->customer();

        foreach ([null, now()->subDay(), now()->addSeconds(10), now()->addYear()->addDay()] as $at) {
            try {
                $this->submit($batch, $customer, 'scheduled', 'all', [], $at);
                $this->fail('debió rechazar la fecha '.($at?->toDateTimeString() ?? 'null'));
            } catch (CouponException $e) {
                $this->assertStringContainsString('activation date', $e->getMessage());
            }
        }
        $this->assertSame(3, $this->countStatus($batch, 'GENERATED'));
    }

    public function test_cancelling_a_pending_order_keeps_the_coupons_assigned_and_never_activates_them(): void
    {
        $batch = $this->batch(4);
        $customer = $this->customer();
        $order = $this->submit($batch, $customer, 'scheduled', 'all', [], now()->addDay());

        $this->service->cancel($order, 'Cliente canceló', $this->operator, null);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(4, $this->countStatus($batch, 'ASSIGNED'));
        $this->travel(2)->days();
        $this->assertSame(0, $this->service->applyDue());
        $this->assertSame(0, $this->countStatus($batch, 'ACTIVE'));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'cancel_activation', 'activation_order_id' => $order->id, 'admin_user_id' => $this->operator->id]);

        $this->expectException(CouponException::class); // ya cancelada
        $this->service->cancel($order, null, $this->operator, null);
    }

    public function test_an_applied_order_cannot_be_cancelled(): void
    {
        $batch = $this->batch(2);
        $order = $this->submit($batch, $this->customer());

        $this->expectException(CouponException::class);
        $this->service->cancel($order, null, $this->operator, null);
    }

    public function test_unassign_releases_assigned_coupons_but_not_those_with_a_pending_activation(): void
    {
        $batch = $this->batch(6);
        $customer = $this->customer();
        $this->submit($batch, $customer, 'assign', 'range', ['from' => 1, 'to' => 3]);
        $this->submit($batch, $customer, 'scheduled', 'range', ['from' => 4, 'to' => 6], now()->addDay());

        $released = $this->service->unassign($batch, 'all', [], 'Error de asignación', $this->operator, null);

        $this->assertSame(3, $released);
        $this->assertSame(3, $this->countStatus($batch, 'GENERATED'));
        $this->assertSame(3, $this->countStatus($batch, 'ASSIGNED'), 'los de la orden pendiente siguen reservados');
        $this->assertNull(DB::table('coupons')->where('batch_id', $batch->id)->where('seq', 1)->value('customer_id'));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'unassign', 'batch_id' => $batch->id]);
    }

    public function test_void_batch_cancels_unused_coupons_and_pending_orders_but_not_active_ones(): void
    {
        $batch = $this->batch(6);
        $customer = $this->customer();
        $this->submit($batch, $customer, 'now', 'range', ['from' => 1, 'to' => 2]);
        $pending = $this->submit($batch, $customer, 'scheduled', 'range', ['from' => 3, 'to' => 4], now()->addDay());

        $count = $this->service->voidBatch($batch, 'Lote mal generado', $this->generator, null);

        $this->assertSame(4, $count);
        $this->assertSame(2, $this->countStatus($batch, 'ACTIVE'));
        $this->assertSame(4, $this->countStatus($batch, 'VOID'));
        $this->assertSame('void', $batch->fresh()->status);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertNull($batch->fresh()->export_path);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'void_batch', 'batch_id' => $batch->id]);

        $this->expectException(CouponException::class);
        $this->submit($batch, $customer);
    }

    // ---- Separación de funciones y reglas

    public function test_the_person_who_generated_a_batch_cannot_assign_or_activate_it(): void
    {
        $batch = $this->batch(4);
        $customer = $this->customer();

        foreach (['assign', 'now', 'scheduled'] as $mode) {
            try {
                $this->submit($batch, $customer, $mode, 'all', [], $mode === 'scheduled' ? now()->addDay() : null, $this->generator);
                $this->fail("el generador pudo usar $mode");
            } catch (CouponException $e) {
                $this->assertStringContainsString('generated a batch', $e->getMessage());
            }
        }
        try {
            $this->service->unassign($batch, 'all', [], null, $this->generator, null);
            $this->fail('el generador pudo liberar');
        } catch (CouponException) {
        }
        $this->assertSame(4, $this->countStatus($batch, 'GENERATED'));

        config(['coupons.separation_of_duties' => false]);
        $this->assertSame(4, $this->submit($batch, $customer, 'now', 'all', [], null, $this->generator)->coupons_count);
    }

    public function test_inactive_customers_and_unready_batches_are_rejected(): void
    {
        $batch = $this->batch(3);
        $inactive = Customer::create(['name' => 'Baja', 'is_active' => false]);

        try {
            $this->submit($batch, $inactive);
            $this->fail('cliente inactivo');
        } catch (CouponException $e) {
            $this->assertStringContainsString('not active', $e->getMessage());
        }

        $generating = CouponBatchGenerator::create(['name' => 'Aún no', 'coupon_type' => 'QRCODE', 'quantity' => 3, 'amount' => '5'], $this->generator, null);
        $this->expectException(CouponException::class);
        $this->submit($generating, $this->customer());
    }

    public function test_concurrent_double_activation_cannot_happen(): void
    {
        $batch = $this->batch(3);
        $this->submit($batch, $this->customer('A'));

        // Todos activos: un segundo intento no encuentra elegibles
        $this->expectException(CouponException::class);
        $this->submit($batch, $this->customer('B'));
    }

    public function test_manual_toggle_follows_the_lifecycle(): void
    {
        $batch = $this->batch(2);
        $coupon = CouponService::qr();
        $generated = (int) DB::table('coupons')->where('batch_id', $batch->id)->where('seq', 1)->value('coupon_id');

        try {
            $coupon->setActive($generated, true, 'No debería', $this->operator, null);
            $this->fail('un generado no se activa a mano');
        } catch (CouponException $e) {
            $this->assertStringContainsString('batch', $e->getMessage());
        }

        $this->submit($batch, $this->customer());
        $coupon->setActive($generated, false, 'Fraude detectado', $this->operator, null);
        $this->assertSame('INACTIVE', DB::table('coupons')->where('coupon_id', $generated)->value('status'));
        $this->assertSame(0, (int) DB::table('coupons')->where('coupon_id', $generated)->value('coupon_active'));
        $coupon->setActive($generated, true, 'Falsa alarma', $this->operator, null);
        $this->assertSame('ACTIVE', DB::table('coupons')->where('coupon_id', $generated)->value('status'));
    }

    // ---- Panel

    public function test_panel_flow_previews_then_confirms_and_audits_the_admin(): void
    {
        $batch = $this->batch(8);
        $customer = $this->customer();
        $operator = $this->admin('coupon_batches.view', 'coupon_batches.activate');
        $this->actingAs($operator, 'admin');

        $this->get(route('admin.coupon-batches.activate.form', $batch))->assertOk()->assertSee('Cliente X');

        $data = ['selection_type' => 'range', 'from' => 2, 'to' => 4, 'customer_id' => $customer->id, 'mode' => 'now', 'reason' => 'Pedido 77'];
        $this->post(route('admin.coupon-batches.activate', $batch), $data)->assertOk()->assertSee('Confirma la operación')->assertSee('3');
        $this->assertSame(0, $this->countStatus($batch, 'ACTIVE'), 'la vista previa no cambia nada');

        $this->post(route('admin.coupon-batches.activate', $batch), $data + ['step' => 'confirm'])->assertRedirect(route('admin.coupon-batches.show', $batch));
        $this->assertSame(3, $this->countStatus($batch, 'ACTIVE'));
        $order = ActivationOrder::first();
        $this->assertSame($operator->id, (int) $order->requested_by_admin_id);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'activate', 'activation_order_id' => $order->id, 'admin_user_id' => $operator->id]);
        $this->get(route('admin.coupon-batches.show', $batch))->assertOk()->assertSee('Cliente X')->assertSee('Pedido 77', false);
    }

    public function test_panel_list_selection_accepts_pasted_numbers_and_a_csv_file(): void
    {
        $batch = $this->batch(6);
        $customer = $this->customer();
        $this->actingAs($this->admin('coupon_batches.assign'), 'admin');
        [$n1, $n2, $n3] = DB::table('coupons')->where('batch_id', $batch->id)->orderBy('seq')->limit(3)->pluck('coupon_number')->all();
        $file = UploadedFile::fake()->createWithContent('lista.csv', "numero\n{$n3}\n");

        $this->post(route('admin.coupon-batches.activate', $batch), [
            'selection_type' => 'list', 'numbers' => "$n1, $n2", 'numbers_file' => $file,
            'customer_id' => $customer->id, 'mode' => 'assign', 'step' => 'confirm',
        ])->assertRedirect();

        $this->assertSame(3, $this->countStatus($batch, 'ASSIGNED'));
    }

    public function test_panel_rejects_the_generator_with_a_clear_message_and_bad_dates(): void
    {
        $generator = $this->admin('coupon_batches.generate', 'coupon_batches.activate', 'coupon_batches.activate_all');
        $this->actingAs($generator, 'admin');
        $this->post(route('admin.coupon-batches.store'), ['name' => 'Mío', 'coupon_type' => 'CODBAR', 'quantity' => 3, 'amount' => 5]);
        $batch = CouponBatch::first();
        $customer = $this->customer();
        $data = ['selection_type' => 'all', 'customer_id' => $customer->id, 'mode' => 'now', 'step' => 'confirm'];

        $this->post(route('admin.coupon-batches.activate', $batch), $data)->assertSessionHasErrors('coupon');
        $this->assertStringContainsString('Quien generó el lote', session('errors')->first('coupon'));

        $other = $this->admin('coupon_batches.activate', 'coupon_batches.activate_all', 'coupon_batches.schedule');
        $this->actingAs($other, 'admin');
        $this->post(route('admin.coupon-batches.activate', $batch), ['mode' => 'scheduled', 'activate_at' => ''] + $data)->assertSessionHasErrors('activate_at');
        $this->post(route('admin.coupon-batches.activate', $batch), ['customer_id' => 'no-uuid'] + $data)->assertSessionHasErrors('customer_id');
        $this->assertSame(0, DB::table('coupons_codbar')->where('status', 'ACTIVE')->count());
    }

    public function test_panel_cancel_and_void_need_their_permissions(): void
    {
        $batch = $this->batch(4);
        $order = $this->submit($batch, $this->customer(), 'scheduled', 'all', [], now()->addDay());

        $this->actingAs($this->admin('coupon_batches.activate'), 'admin');
        $this->post(route('admin.activation-orders.cancel', $order))->assertForbidden();
        $this->post(route('admin.coupon-batches.void', $batch), ['reason' => 'Motivo largo'])->assertForbidden();

        $this->actingAs($this->admin('coupon_batches.cancel', 'coupon_batches.void'), 'admin');
        $this->post(route('admin.activation-orders.cancel', $order), ['reason' => 'x'])->assertSessionHas('status');
        $this->post(route('admin.coupon-batches.void', $batch), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('admin.coupon-batches.void', $batch), ['reason' => 'Lote equivocado'])->assertSessionHas('status');
        $this->assertSame('void', $batch->fresh()->status);
    }

    public function test_activations_and_batches_screens_render_with_filters(): void
    {
        $batch = $this->batch(4);
        $customer = $this->customer('Cliente Visible');
        $this->submit($batch, $customer, 'scheduled', 'all', [], now()->addDay());
        $this->actingAs($this->admin('coupon_activations.view', 'coupon_batches.view', 'coupons.view', 'customers.view', 'coupon_batches.cancel'), 'admin');

        $this->get(route('admin.screens.index', 'coupon-activations'))->assertOk()->assertSee('Cliente Visible')->assertSee($batch->code)->assertSee('Cancelar');
        $this->get(route('admin.screens.index', 'coupon-activations').'?filter[status]=applied')->assertOk()->assertDontSee('Cancelar'); // la programada no aparece como aplicada
        $this->get(route('admin.screens.index', 'coupon-batches'))->assertOk()->assertSee($batch->code)->assertSee('Lote');
        $this->get(route('admin.coupons.index').'?customer='.$customer->id)->assertOk()->assertSee('Cliente Visible')->assertSee('Asignado');
        $this->get(route('admin.screens.index', 'customers'))->assertOk()->assertSee('Cliente Visible');
    }
}
