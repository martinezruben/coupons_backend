<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Support\CouponToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CouponPrintedViewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    private function qr(string $token): int
    {
        return DB::table('coupons')->insertGetId(['coupon_number' => 'Q123456789012', 'coupon_token' => $token, 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => '50.00', 'coupon_balance' => '50.00', 'coupon_expire_date' => '2027-12-31'], 'coupon_id');
    }

    private function bar(): int
    {
        return DB::table('coupons_codbar')->insertGetId(['coupon_number' => '123456789012', 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => '30.00', 'coupon_balance' => '30.00', 'coupon_expire_date' => '2027-12-31'], 'coupon_id');
    }

    public function test_a_barcode_coupon_is_drawn_and_every_view_is_audited(): void
    {
        $id = $this->bar();
        $this->actingAs($this->admin('coupon_barcodes.view', 'coupon_barcodes.detail', 'coupon_barcodes.view_printed'), 'admin');

        $res = $this->getJson(route('admin.coupons.printed', ['barcode', $id]))->assertOk()->assertJsonPath('kind', 'barcode')->assertJsonPath('number', '123456789012')->assertJsonPath('amount', '$30.00');
        $this->assertStringContainsString('<svg', $res->json('svg'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'view_printed', 'entity_id' => (string) $id]);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_a_qr_with_a_clear_token_draws_the_printed_payload_but_a_hashed_one_cannot(): void
    {
        $legacy = $this->qr('plain-legacy-token');
        $this->actingAs($this->admin('coupons.view', 'coupons.detail', 'coupons.view_printed'), 'admin');

        $res = $this->getJson(route('admin.coupons.printed', ['qr', $legacy]))->assertOk()->assertJsonPath('kind', 'qr')->assertJsonPath('note', null);
        $this->assertStringContainsString('<svg', $res->json('svg'));

        // Cupón nuevo: hash para validar + token cifrado para dibujar; el QR lleva exactamente el contenido impreso
        DB::table('coupons')->where('coupon_id', $legacy)->update(['coupon_token' => CouponToken::hash('abc'), 'coupon_token_enc' => CouponToken::seal('abc')]);
        $this->getJson(route('admin.coupons.printed', ['qr', $legacy]))->assertOk()->assertJsonPath('note', null)
            ->assertJsonPath('svg', fn ($svg) => str_contains((string) $svg, '<svg'));

        // Anterior a guardar el token cifrado (solo hash), o con otra APP_KEY: no se puede dibujar y se explica
        DB::table('coupons')->where('coupon_id', $legacy)->update(['coupon_token_enc' => null]);
        $this->getJson(route('admin.coupons.printed', ['qr', $legacy]))->assertOk()->assertJsonPath('svg', null)
            ->assertJsonPath('note', fn ($n) => str_contains($n, 'antes de que el token se guardara cifrado'));
        DB::table('coupons')->where('coupon_id', $legacy)->update(['coupon_token_enc' => 'basura-que-no-descifra']);
        $this->getJson(route('admin.coupons.printed', ['qr', $legacy]))->assertOk()->assertJsonPath('svg', null);
    }

    public function test_without_the_permission_there_is_no_button_and_no_code(): void
    {
        $id = $this->bar();
        $this->actingAs($this->admin('coupon_barcodes.view', 'coupon_barcodes.detail'), 'admin');

        $this->getJson(route('admin.coupons.printed', ['barcode', $id]))->assertForbidden();
        $this->get(route('admin.coupons.show', ['barcode', $id]))->assertOk()->assertDontSee('Ver cupón');

        $this->actingAs($this->admin('coupon_barcodes.view', 'coupon_barcodes.detail', 'coupon_barcodes.view_printed'), 'admin');
        $page = $this->get(route('admin.coupons.show', ['barcode', $id]))->assertOk()->assertSee('Ver cupón');
        // El código no viaja en la página de detalle: se pide al abrir el modal
        $this->assertStringNotContainsString('<svg viewBox', $page->getContent());
    }
}
