<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\CouponAuditLog;
use App\Models\CouponBatch;
use App\Models\CouponTransaction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\TopUpRule;
use App\Models\User;
use App\Services\CouponActivationService;
use App\Services\CouponBatchGenerator;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CouponLocationAndAuditDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('exports');
        parent::tearDown();
    }

    private function super(): AdminUser
    {
        $a = AdminUser::factory()->create(['name' => 'Sara Super']);
        $a->assignRole(Role::findOrCreate('super-admin', 'admin'));

        return $a;
    }

    private function device(Location $location): Device
    {
        $device = Device::factory()->create(['is_enabled' => true, 'location_id' => $location->id]);
        License::factory()->create(['device_id' => $device->id, 'status' => 'ACTIVE']);

        return $device;
    }

    private function bar(string $n = 'B1'): int
    {
        return DB::table('coupons_codbar')->insertGetId(['coupon_number' => $n, 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => '100', 'coupon_balance' => '100', 'coupon_expire_date' => now()->addYear()->toDateString()], 'coupon_id');
    }

    // ---- Localidad que llega con el canje del dispositivo Android

    public function test_the_redeem_location_comes_from_the_android_request_and_falls_back_to_the_device(): void
    {
        $user = User::factory()->create(['role' => 'MANAGER']);
        Sanctum::actingAs($user, $user->couponAbilities());
        $home = Location::factory()->create(['is_active' => true]);
        $other = Location::factory()->create(['is_active' => true]);
        $device = $this->device($home);
        $this->bar('B1');
        $headers = ['Device-Fingerprint' => $device->device_fingerprint];

        // La localidad enviada en la transacción manda
        $this->postJson('/api/transactioncb', ['amount' => 10, 'coupons' => ['B1'], 'location_id' => $other->id], $headers)->assertOk();
        $tx = DB::table('coupon_transactions')->orderByDesc('transaction_id')->first();
        $this->assertSame($other->id, $tx->location_id);
        $this->assertSame('request', $tx->location_source);
        $this->assertSame($device->id, $tx->device_id);

        // Sin localidad en la petición, la del dispositivo registrado
        $this->postJson('/api/transactioncb', ['amount' => 10, 'coupons' => ['B1']], $headers)->assertOk();
        $tx = DB::table('coupon_transactions')->orderByDesc('transaction_id')->first();
        $this->assertSame($home->id, $tx->location_id);
        $this->assertSame('device', $tx->location_source);

        // Sin dispositivo ni localidad: queda vacía
        $this->postJson('/api/transactioncb', ['amount' => 10, 'coupons' => ['B1']])->assertOk();
        $tx = DB::table('coupon_transactions')->orderByDesc('transaction_id')->first();
        $this->assertNull($tx->location_id);
        $this->assertNull($tx->location_source);

        // Una localidad inexistente se rechaza
        $this->postJson('/api/transactioncb', ['amount' => 10, 'coupons' => ['B1'], 'location_id' => (string) Str::uuid()], $headers)->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->postJson('/api/transactioncb', ['amount' => 10, 'coupons' => ['B1'], 'location_id' => 'no-uuid'], $headers)->assertStatus(422);
    }

    public function test_the_topup_location_is_recorded_and_used_by_the_rules_and_reversals_keep_it(): void
    {
        $user = User::factory()->create(['role' => 'MANAGER']);
        Sanctum::actingAs($user, $user->couponAbilities());
        $allowed = Location::factory()->create(['is_active' => true]);
        $denied = Location::factory()->create(['is_active' => true]);
        DB::table('coupons')->insert(['coupon_number' => 'Q1', 'coupon_token' => 'tok', 'coupon_active' => 1, 'coupon_amount' => '10', 'coupon_balance' => '10', 'coupon_expire_date' => now()->addYear()->toDateString()]);
        TopUpRule::create(['name' => 'Solo sede A', 'type' => 'rule', 'assign_all' => true, 'location_ids' => [$allowed->id]]);

        $this->postJson('/api/topup', ['coupon_number' => 'Q1', 'coupon_token' => 'tok', 'amount' => 5, 'location_id' => $denied->id])->assertStatus(422);
        $this->postJson('/api/topup', ['coupon_number' => 'Q1', 'coupon_token' => 'tok', 'amount' => 5, 'location_id' => $allowed->id])->assertOk();
        $tx = DB::table('coupon_transactions')->where('kind', 'TOPUP')->first();
        $this->assertSame([$allowed->id, 'request'], [$tx->location_id, $tx->location_source]);

        $this->postJson('/api/transaction', ['amount' => 3, 'coupons' => ['Q1'], 'tokens' => ['tok'], 'location_id' => $allowed->id])->assertOk();
        $redeem = CouponTransaction::where('kind', 'REDEEM')->first();
        CouponService::reverse($redeem, 'Error', $this->super(), null);
        $reversal = DB::table('coupon_transactions')->where('kind', 'REVERSAL')->first();
        $this->assertSame([$allowed->id, 'request'], [$reversal->location_id, $reversal->location_source]);
    }

    // ---- Reportes con la localidad de canje

    private function sold(): array
    {
        $creator = AdminUser::factory()->create();
        $batch = CouponBatchGenerator::create(['name' => 'Lote Sedes', 'coupon_type' => 'CODBAR', 'quantity' => 3, 'amount' => 100], $creator, null);
        (new CouponBatchGenerator)->generate($batch);
        $customer = Customer::create(['name' => 'Cliente Ruta']);
        app(CouponActivationService::class)->submit($batch->fresh(), $customer, 'now', null, 'all', [], null, $this->super(), null);
        $numbers = DB::table('coupons_codbar')->where('batch_id', $batch->id)->orderBy('seq')->pluck('coupon_number');
        $centro = Location::factory()->create(['name' => 'Sede Centro', 'is_active' => true]);
        $norte = Location::factory()->create(['name' => 'Sede Norte', 'is_active' => true]);
        $svc = CouponService::barcode();
        $svc->redeem([$numbers[0]], null, '40.00', null, null, null, $centro->id);
        $svc->redeem([$numbers[1]], null, '25.00', null, null, null, $centro->id);
        $svc->redeem([$numbers[2]], null, '10.00', null, null, null, $norte->id);
        $svc->redeem([$numbers[2]], null, '5.00', null, null, null); // sin localidad

        return [$batch, $customer];
    }

    public function test_the_batch_and_customer_reports_show_where_coupons_were_redeemed(): void
    {
        [$batch, $customer] = $this->sold();
        $this->actingAs($this->super(), 'admin');

        $html = $this->get(route('admin.reports.batches'))->assertOk()->assertSee('Dónde se canjeó')->getContent();
        $this->assertStringContainsString('Sede Centro · $65.00', $html);
        $this->assertStringContainsString('Sede Norte · $10.00', $html);
        $this->assertStringContainsString('Sin localidad · $5.00', $html);

        $csv = $this->get(route('admin.reports.batches', ['export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Dónde se canjeó', $csv);
        $this->assertStringContainsString('Sede Centro: 65.00 | Sede Norte: 10.00 | Sin localidad: 5.00', $csv);

        $html = $this->get(route('admin.reports.customers'))->assertOk()->assertSee('Dónde canjeó')->getContent();
        $this->assertStringContainsString('Sede Centro · $65.00', $html);
        $csv = $this->get(route('admin.reports.customers', ['export' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Sede Centro: 65.00 | Sede Norte: 10.00 | Sin localidad: 5.00', $csv);
    }

    // ---- Detalle de la auditoría de cupones

    public function test_the_coupon_audit_detail_returns_everything_about_the_action(): void
    {
        [$batch, $customer] = $this->sold();
        $super = $this->super();
        $this->actingAs($super, 'admin');
        $number = DB::table('coupons_codbar')->where('batch_id', $batch->id)->value('coupon_number');
        CouponService::barcode()->restore($number, '77.00', 'Ajuste por reclamo del cliente', $super, '10.1.1.1');

        $log = CouponAuditLog::where('action', 'activate')->first();
        $json = $this->getJson(route('admin.coupon-audit-logs.show', $log->id))->assertOk()->json();
        $this->assertSame('Activación', $json['action_label']);
        $this->assertNotEmpty($json['description']);
        $this->assertSame($batch->code, $json['batch']['code']);
        $this->assertSame('Lote Sedes', $json['batch']['name']);
        $this->assertSame('Cliente Ruta', $json['order']['customer']);
        $this->assertSame('Activar ahora', $json['order']['mode']);
        $this->assertSame(3, $json['order']['coupons']);
        $this->assertSame('Todos los elegibles', $json['order']['selection']);
        $this->assertSame('Sara Super', $json['actor']['name']);

        $restore = CouponAuditLog::where('action', 'restore')->first();
        $json = $this->getJson(route('admin.coupon-audit-logs.show', $restore->id))->assertOk()->json();
        $this->assertSame('Ajuste de saldo', $json['action_label']);
        $this->assertSame('Ajuste por reclamo del cliente', $json['reason']);
        $this->assertSame('77.00', $json['new_value']);
        $this->assertSame('Cupón de barras', $json['coupon_type']);
        $this->assertSame($number, $json['coupon_number']);
        $this->assertNotNull($json['coupon_url']);
        $this->assertSame('10.1.1.1', $json['ip']);
    }

    public function test_the_full_comment_is_kept_when_it_is_longer_than_the_column(): void
    {
        $batch = CouponBatch::create(['code' => 'L-X', 'name' => 'X', 'coupon_type' => 'CODBAR', 'quantity' => 1, 'amount' => 5, 'status' => 'ready', 'created_by_admin_id' => AdminUser::factory()->create()->id]);
        $long = 'Comentario largo '.str_repeat('detalle ', 80);
        CouponBatchGenerator::audit($batch, 'void_batch', $this->super(), '1.2.3.4', '0', '3', $long);

        $log = CouponAuditLog::latest('id')->first();
        $this->assertSame(255, mb_strlen($log->reason));
        $this->assertSame($long, $log->reason_full);

        $this->actingAs($this->super(), 'admin')->getJson(route('admin.coupon-audit-logs.show', $log->id))->assertOk()->assertJsonPath('reason', $long);
    }

    public function test_the_audit_list_has_the_detail_button_translated_actions_and_permission(): void
    {
        [$batch] = $this->sold();
        $viewer = AdminUser::factory()->create();
        Permission::findOrCreate('coupon_audit_logs.view', 'admin');
        $viewer->givePermissionTo('coupon_audit_logs.view');

        $html = $this->actingAs($viewer, 'admin')->get(route('admin.screens.index', 'coupon-audit-logs'))->assertOk()->getContent();
        $this->assertStringContainsString('Ver detalle', $html);
        $this->assertStringContainsString('couponAuditDetail', $html);
        $this->assertStringContainsString('Activación', $html);
        $this->assertStringNotContainsString('>activate<', $html);

        $log = CouponAuditLog::first();
        $this->getJson(route('admin.coupon-audit-logs.show', $log->id))->assertOk();
        $this->actingAs(AdminUser::factory()->create(), 'admin')->getJson(route('admin.coupon-audit-logs.show', $log->id))->assertForbidden();
        $this->actingAs($viewer, 'admin')->getJson(route('admin.coupon-audit-logs.show', 999999))->assertNotFound();
    }
}
