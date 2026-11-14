<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\BarcodeCoupon;
use App\Models\CouponBatch;
use App\Models\CouponTransaction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Location;
use App\Services\CouponActivationService;
use App\Services\CouponBatchGenerator;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CouponDetailTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $super;

    private CouponBatch $batch;

    private Customer $customer;

    private Device $device;

    private string $number;

    private int $couponId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = AdminUser::factory()->create(['name' => 'Sara Supervisora']);
        $this->super->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $creator = AdminUser::factory()->create(['name' => 'Gina Generadora']);
        $this->batch = CouponBatchGenerator::create(['name' => 'Lote Navidad', 'coupon_type' => 'CODBAR', 'quantity' => 3, 'amount' => 100], $creator, null);
        (new CouponBatchGenerator)->generate($this->batch);
        $this->batch = $this->batch->fresh();

        $this->customer = Customer::create(['name' => 'Cliente Estrella']);
        app(CouponActivationService::class)->submit($this->batch, $this->customer, 'now', null, 'quantity', ['quantity' => 1], 'Venta mayorista', $this->super, null);

        $row = DB::table('coupons_codbar')->where('batch_id', $this->batch->id)->where('status', 'ACTIVE')->first();
        $this->number = $row->coupon_number;
        $this->couponId = $row->coupon_id;

        $location = Location::create(['name' => 'Sede Centro', 'is_active' => true]);
        $this->device = Device::create(['location_id' => $location->id, 'device_fingerprint' => 'fp-1', 'name' => 'Caja 1', 'is_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('exports');
        parent::tearDown();
    }

    private function url(): string
    {
        return route('admin.coupons.show', ['barcode', $this->couponId]);
    }

    public function test_the_detail_shows_the_whole_life_of_the_coupon(): void
    {
        $svc = CouponService::barcode();
        $svc->redeem([$this->number], null, '30.00', $this->device, null, '10.0.0.5');
        $svc->topUp($this->number, null, '20.00', null, $this->super, '10.0.0.9', false, 'Pago en caja');
        $svc->restore($this->number, '70.00', 'Corrección manual', $this->super, '10.0.0.9');
        $svc->setActive($this->couponId, false, 'Sospecha de fraude', $this->super, '10.0.0.9');
        $tx = CouponTransaction::where('kind', 'REDEEM')->first();
        CouponService::reverse($tx, 'Cobro duplicado', $this->super, '10.0.0.9');

        $html = $this->actingAs($this->super, 'admin')->get($this->url())->assertOk()->getContent();

        foreach ([
            'Cupón de barras', $this->number, 'Cupón generado', 'Lote Navidad', 'Gina Generadora', 'Monto inicial', // creación
            'Asignado a Cliente Estrella', 'Venta mayorista', 'Activado', 'Sara Supervisora',                  // asignación y activación
            'Cobro', 'Localidad: Sede Centro', 'Dispositivo: Caja 1', 'IP: 10.0.0.5', '−$30.00',               // cobro con localidad y dispositivo
            'Recarga', '+$20.00', 'Saldo ajustado por un administrador', 'Corrección manual', 'Cupón desactivado', 'Sospecha de fraude',
            'Reversa de cobro', 'Cobro duplicado', 'Resumen de uso', 'Sede Centro',
        ] as $text) {
            $this->assertStringContainsString($text, $html, "falta «{$text}» en el historial");
        }
        // Todos los momentos en orden: lo más reciente primero (la reversa antes que la creación)
        $this->assertLessThan(strpos($html, 'Cupón generado'), strpos($html, 'Reversa de cobro'));
    }

    public function test_the_balance_after_each_movement_is_calculated(): void
    {
        $svc = CouponService::barcode();
        $svc->redeem([$this->number], null, '30.00', $this->device, null, null); // 100 → 70
        $svc->topUp($this->number, null, '20.00', null, $this->super, null, false); // 70 → 90

        $html = $this->actingAs($this->super, 'admin')->get($this->url())->getContent();

        $this->assertStringContainsString('saldo después: $70.00', $html);
        $this->assertStringContainsString('saldo después: $90.00', $html);
        $this->assertStringNotContainsString('no coincide con el saldo actual', $html);
    }

    public function test_summary_totals_net_of_reversals(): void
    {
        $svc = CouponService::barcode();
        $svc->redeem([$this->number], null, '30.00', $this->device, null, null);
        $svc->redeem([$this->number], null, '10.00', $this->device, null, null);
        CouponService::reverse(CouponTransaction::where('kind', 'REDEEM')->orderBy('transaction_id')->first(), 'Error de captura', $this->super, null);

        $html = $this->actingAs($this->super, 'admin')->get($this->url())->getContent();

        $this->assertMatchesRegularExpression('/Cobrado neto<\/dt><dd[^>]*>\$10\.00/', preg_replace('/\s+/', ' ', $html));
    }

    public function test_data_changes_with_before_and_after_appear(): void
    {
        $this->actingAs($this->super, 'admin');
        BarcodeCoupon::find($this->couponId)->forceFill(['coupon_expire_date' => '2031-01-01'])->save();

        $html = $this->get($this->url())->getContent();

        $this->assertStringContainsString('Cambio de datos registrado', $html);
        $this->assertStringContainsString('coupon_expire_date', $html);
        $this->assertStringContainsString('Ver antes y después', $html);
        $this->assertStringContainsString('2031-01-01', $html);
    }

    public function test_views_of_the_coupon_are_listed_as_accesses(): void
    {
        $this->actingAs($this->super, 'admin');
        $this->get($this->url())->assertOk();
        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('Quién consultó este cupón', $html);
        $this->assertStringContainsString('Sara Supervisora', $html);
    }

    public function test_the_detail_respects_permissions_and_customer_scope(): void
    {
        // Sin permiso
        $this->actingAs(AdminUser::factory()->create(), 'admin')->get($this->url())->assertForbidden();

        // Con el permiso de barras pero otro cliente en su alcance: 404
        $restricted = AdminUser::factory()->create(['customer_scope' => 'selected']);
        Permission::findOrCreate('coupon_barcodes.view', 'admin');
        $restricted->givePermissionTo('coupon_barcodes.view');
        $restricted->customers()->sync([Customer::create(['name' => 'Otro'])->id]);
        $this->actingAs($restricted, 'admin')->get($this->url())->assertNotFound();

        $restricted->customers()->sync([$this->customer->id]);
        $this->actingAs($restricted->fresh(), 'admin')->get($this->url())->assertOk();

        // Un cupón que no existe
        $this->get(route('admin.coupons.show', ['barcode', 999999]))->assertNotFound();
    }

    public function test_qr_detail_never_reveals_the_token(): void
    {
        $qr = CouponBatchGenerator::create(['name' => 'QR', 'coupon_type' => 'QRCODE', 'quantity' => 1, 'amount' => 10], AdminUser::factory()->create(), null);
        (new CouponBatchGenerator)->generate($qr);
        $row = DB::table('coupons')->where('batch_id', $qr->id)->first();

        $html = $this->actingAs($this->super, 'admin')->get(route('admin.coupons.show', ['qr', $row->coupon_id]))->assertOk()->assertSee('Cupón QR')->getContent();

        $this->assertStringNotContainsString($row->coupon_token, $html);
        $this->assertStringNotContainsString('h1:', $html);
    }

    public function test_the_list_shows_type_icons_filters_and_links_to_the_detail(): void
    {
        $qr = CouponBatchGenerator::create(['name' => 'QR', 'coupon_type' => 'QRCODE', 'quantity' => 1, 'amount' => 10], AdminUser::factory()->create(), null);
        (new CouponBatchGenerator)->generate($qr);
        $qrNumber = DB::table('coupons')->where('batch_id', $qr->id)->value('coupon_number');

        $this->actingAs($this->super, 'admin');
        $this->get(route('admin.coupons.index'))->assertOk()->assertSee($qrNumber)->assertSee($this->number)->assertSee($this->url(), false)
            ->assertSee('Cupón QR')->assertSee('Cupón de barras');

        $this->get(route('admin.coupons.index', ['type' => 'QRCODE']))->assertSee($qrNumber)->assertDontSee($this->number);
        $this->get(route('admin.coupons.index', ['type' => 'CODBAR', 'status' => 'ACTIVE']))->assertSee($this->number)->assertDontSee($qrNumber);
        $this->get(route('admin.coupons.index', ['batch' => $qr->id]))->assertSee($qrNumber)->assertDontSee($this->number);
        $this->get(route('admin.coupons.index', ['customer' => $this->customer->id]))->assertSee($this->number)->assertDontSee($qrNumber);
        $this->get(route('admin.coupons.index', ['sort' => 'balance', 'dir' => 'asc']))->assertOk();
        $this->get(route('admin.coupons.index', ['type' => 'OTRO']))->assertSessionHasErrors('type');
    }
}
