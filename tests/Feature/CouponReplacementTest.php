<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\CouponReplacement;
use App\Models\User;
use App\Support\CouponToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CouponReplacementTest extends TestCase
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

    private function qr(string $balance = '35.50'): int
    {
        return DB::table('coupons')->insertGetId(['coupon_number' => 'NAV123456789012', 'coupon_token' => CouponToken::hash('oldtok'), 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => '50.00', 'coupon_balance' => $balance, 'coupon_expire_date' => '2027-12-31', 'customer_id' => null], 'coupon_id');
    }

    private function bar(): int
    {
        return DB::table('coupons_codbar')->insertGetId(['coupon_number' => '123456789012', 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => '30.00', 'coupon_balance' => '30.00', 'coupon_expire_date' => '2027-12-31'], 'coupon_id');
    }

    public function test_a_replacement_needs_another_admin_then_moves_the_balance_and_voids_the_lost_coupon(): void
    {
        config(['coupons.replace_requires_approval' => true]);
        $id = $this->qr();
        $requester = $this->admin('coupons.view', 'coupons.detail', 'coupons.replace');
        $approver = $this->admin('coupon_replacements.view', 'coupon_replacements.approve');

        $this->actingAs($requester, 'admin')->post(route('admin.coupons.replace', ['qr', $id]), ['reason' => 'Cupón perdido por el cliente'])->assertSessionHas('status');
        $rep = CouponReplacement::firstOrFail();
        $this->assertSame('PENDING', $rep->status);
        $this->assertSame('ACTIVE', DB::table('coupons')->where('coupon_id', $id)->value('status')); // aún no pasa nada
        $this->post(route('admin.coupons.replace', ['qr', $id]), ['reason' => 'Otra vez el mismo'])->assertSessionHasErrors('coupon'); // ya hay una pendiente

        // Quien lo pidió no lo aprueba
        $requester->givePermissionTo(Permission::findOrCreate('coupon_replacements.approve', 'admin'));
        $this->post(route('admin.replacements.approve', $rep->id))->assertSessionHasErrors('coupon');

        $this->actingAs($approver, 'admin')->get(route('admin.replacements.index'))->assertOk()->assertSee('Cupón perdido por el cliente');
        $this->post(route('admin.replacements.approve', $rep->id))->assertSessionHas('status');

        $rep->refresh();
        $this->assertSame('APPROVED', $rep->status);
        $old = DB::table('coupons')->where('coupon_id', $id)->first();
        $new = DB::table('coupons')->where('coupon_id', $rep->new_coupon_id)->first();
        $this->assertSame('VOID', $old->status);
        $this->assertEquals(0, $old->coupon_balance);
        $this->assertSame('ACTIVE', $new->status);
        $this->assertEquals(35.5, $new->coupon_balance);
        $this->assertSame('2027-12-31', substr((string) $new->coupon_expire_date, 0, 10));
        $this->assertMatchesRegularExpression('/^NAV\d{12}$/', $new->coupon_number);
        $this->assertNotSame($old->coupon_number, $new->coupon_number);
        // El token nuevo valida (hash) y se puede reimprimir (cifrado)
        $plain = Coupon::find($rep->new_coupon_id)->plainToken();
        $this->assertTrue(CouponToken::matches($new->coupon_token, $plain));
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'replace_out', 'coupon_number' => $old->coupon_number]);
        $this->assertDatabaseHas('coupon_audit_logs', ['action' => 'replace_in', 'coupon_number' => $new->coupon_number]);
        $this->post(route('admin.replacements.approve', $rep->id))->assertSessionHasErrors('coupon'); // ya decidido
    }

    public function test_the_lost_coupon_stops_working_and_the_new_one_can_be_redeemed(): void
    {
        config(['coupons.replace_requires_approval' => false]);
        $id = $this->qr('20.00');
        $this->actingAs($this->admin('coupons.view', 'coupons.detail', 'coupons.replace'), 'admin')
            ->post(route('admin.coupons.replace', ['qr', $id]), ['reason' => 'Cupón robado'])->assertRedirect();
        $rep = CouponReplacement::firstOrFail();
        $this->assertSame('APPROVED', $rep->status);

        $user = User::factory()->create(['role' => 'CASHIER']);
        Sanctum::actingAs($user, $user->couponAbilities());
        $this->postJson('/api/transaction', ['amount' => 5, 'coupons' => ['NAV123456789012'], 'tokens' => ['oldtok']])->assertStatus(422)->assertJsonPath('code', 'coupon_inactive_or_expired');
        $new = Coupon::find($rep->new_coupon_id);
        $this->postJson('/api/transaction', ['amount' => 5, 'coupons' => [$new->coupon_number], 'tokens' => [$new->plainToken()]])->assertOk()->assertJsonPath('sumbalance', '20.00');
    }

    public function test_only_active_or_inactive_coupons_with_balance_can_be_replaced_and_barcodes_work(): void
    {
        config(['coupons.replace_requires_approval' => false]);
        $admin = $this->admin('coupons.view', 'coupons.detail', 'coupons.replace', 'coupon_barcodes.view', 'coupon_barcodes.detail', 'coupon_barcodes.replace');
        $this->actingAs($admin, 'admin');

        $empty = $this->qr('0.00');
        $this->post(route('admin.coupons.replace', ['qr', $empty]), ['reason' => 'Sin saldo para mover'])->assertSessionHasErrors('coupon');
        DB::table('coupons')->where('coupon_id', $empty)->update(['coupon_balance' => '10.00', 'status' => 'GENERATED']);
        $this->post(route('admin.coupons.replace', ['qr', $empty]), ['reason' => 'No está activo aún'])->assertSessionHasErrors('coupon');

        $bar = $this->bar();
        $this->post(route('admin.coupons.replace', ['barcode', $bar]), ['reason' => 'Se perdió el cupón'])->assertRedirect();
        $rep = CouponReplacement::where('coupon_type', 'CODBAR')->firstOrFail();
        $this->assertSame('VOID', DB::table('coupons_codbar')->where('coupon_id', $bar)->value('status'));
        $this->assertEquals(30, DB::table('coupons_codbar')->where('coupon_id', $rep->new_coupon_id)->value('coupon_balance'));

        // El detalle muestra el vínculo entre los dos cupones
        $this->get(route('admin.coupons.show', ['barcode', $bar]))->assertOk()->assertSee('fue reemplazado por');
        $this->get(route('admin.coupons.show', ['barcode', $rep->new_coupon_id]))->assertOk()->assertSee('Cupón de reemplazo');
    }

    public function test_without_the_permission_there_is_no_replace_action(): void
    {
        $id = $this->qr();
        $this->actingAs($this->admin('coupons.view', 'coupons.detail'), 'admin');
        $this->post(route('admin.coupons.replace', ['qr', $id]), ['reason' => 'Cupón perdido'])->assertForbidden();
        $this->get(route('admin.coupons.show', ['qr', $id]))->assertOk()->assertDontSee('Reemplazar (perdido)');
    }
}
