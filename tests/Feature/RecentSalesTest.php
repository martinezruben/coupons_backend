<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecentSalesTest extends TestCase
{
    use RefreshDatabase;

    private Location $loc;

    private Device $device;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loc = Location::factory()->create(['is_active' => true, 'name' => 'Zona 1', 'dispensers' => 4]);
        $this->device = Device::factory()->create(['is_enabled' => true, 'location_id' => $this->loc->id]);
        License::factory()->create(['device_id' => $this->device->id, 'status' => 'ACTIVE']);
        $this->h = ['Device-Fingerprint' => $this->device->device_fingerprint];
        foreach (['A1' => '30.00', 'A2' => '50.00', 'B1' => '100.00'] as $n => $bal) {
            DB::table('coupons')->insert(['coupon_number' => $n, 'coupon_token' => 'tok-'.$n, 'coupon_active' => 1, 'status' => 'ACTIVE', 'coupon_amount' => $bal, 'coupon_balance' => $bal, 'coupon_expire_date' => now()->addYear()->toDateString()]);
        }
    }

    private function as(string $role, string $name): User
    {
        $u = User::factory()->create(['role' => $role, 'username' => $name, 'location_id' => $this->loc->id]);
        Sanctum::actingAs($u, $u->couponAbilities());

        return $u;
    }

    private function sell(array $coupons, string $amount, string $key, int $dispenser = 1)
    {
        return $this->postJson('/api/transaction', ['amount' => $amount, 'coupons' => $coupons, 'tokens' => array_map(fn ($c) => 'tok-'.$c, $coupons), 'dispenser' => $dispenser], $this->h + ['Idempotency-Key' => $key]);
    }

    public function test_a_multi_coupon_sale_comes_back_as_one_sale_with_masked_numbers_and_balances(): void
    {
        $this->as('CASHIER', 'ana');
        $res = $this->sell(['A1', 'A2'], '60.00', 'sale-key-0001', 2)->assertOk();
        $this->assertSame('sale-key-0001', $res->json('sale_id'));

        $list = $this->getJson('/api/sales/recent', $this->h)->assertOk()->assertJsonPath('count', 1)->assertJsonPath('scope', 'mine');
        $sale = $list->json('sales.0');
        $this->assertSame('sale-key-0001', $sale['sale_id']);
        $this->assertSame('60.00', $sale['amount']);
        $this->assertSame(2, $sale['dispenser']);
        $this->assertSame('Zona 1', $sale['location_name']);
        $this->assertSame('ana', $sale['cashier']);
        $this->assertSame('completed', $sale['status']);
        $this->assertSame($res->json('transaction_ids'), $sale['transaction_ids']);
        $this->assertCount(2, $sale['coupons']);
        $this->assertSame(['30.00', '30.00'], array_column($sale['coupons'], 'applied'));
        $this->assertSame(['0.00', '20.00'], array_column($sale['coupons'], 'balance')); // saldo de cada cupón tras la venta
        $this->assertSame('**', $sale['coupons'][0]['coupon_number']); // «A1» tiene ≤ 4 caracteres: todo enmascarado
        $this->assertStringNotContainsString('tok-', json_encode($sale));
    }

    public function test_a_sale_whose_response_was_lost_can_be_recovered_by_its_key(): void
    {
        $this->as('CASHIER', 'ana');
        $this->sell(['B1'], '40.00', 'lost-sale-key-1')->assertOk(); // la respuesta «se perdió»: la app solo conoce su clave
        $this->sell(['B1'], '10.00', 'other-sale-key-2')->assertOk();

        $found = $this->getJson('/api/sales/recent?key=lost-sale-key-1', $this->h)->assertOk()->assertJsonPath('count', 1);
        $this->assertSame('40.00', $found->json('sales.0.amount'));
        $this->getJson('/api/sales/recent?key=no-existe-1234', $this->h)->assertOk()->assertJsonPath('count', 0);
    }

    public function test_each_cashier_sees_only_their_sales_and_a_supervisor_sees_the_location(): void
    {
        $this->as('CASHIER', 'ana');
        $this->sell(['A1'], '10.00', 'ana-sale-00001')->assertOk();
        $this->as('CASHIER', 'luis');
        $this->sell(['A2'], '20.00', 'luis-sale-0001')->assertOk();

        $this->getJson('/api/sales/recent', $this->h)->assertOk()->assertJsonPath('count', 1)->assertJsonPath('sales.0.cashier', 'luis');
        $this->getJson('/api/sales/recent?scope=location', $this->h)->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $this->as('MANAGER', 'jefa');
        $this->getJson('/api/sales/recent?scope=location', $this->h)->assertOk()->assertJsonPath('count', 2)->assertJsonPath('sales.0.cashier', 'luis');
        // Otra localidad no aparece
        $other = Location::factory()->create(['is_active' => true]);
        DB::table('coupon_transactions')->insert(['coupon_id' => 1, 'coupon_number' => 'X', 'transaction_amount' => '5.00', 'coupon_type' => 'QRCODE', 'kind' => 'REDEEM', 'location_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/sales/recent?scope=location', $this->h)->assertOk()->assertJsonPath('count', 2);
    }

    public function test_a_reversed_sale_shows_its_status_and_old_rows_and_limits_are_handled(): void
    {
        $this->as('CASHIER', 'ana');
        $this->sell(['A1', 'A2'], '60.00', 'to-reverse-0001')->assertOk();
        $ids = DB::table('coupon_transactions')->where('sale_ref', 'to-reverse-0001')->orderBy('transaction_id')->pluck('transaction_id');

        $this->as('MANAGER', 'jefa');
        $this->postJson("/api/transactions/{$ids[0]}/reverse", ['reason' => 'Cobro equivocado'], $this->h)->assertOk();
        $this->as('CASHIER', 'ana2'); // otro usuario: no ve la venta de ana
        $this->getJson('/api/sales/recent', $this->h)->assertOk()->assertJsonPath('count', 0);

        $ana = User::where('username', 'ana')->first();
        Sanctum::actingAs($ana, $ana->couponAbilities());
        $sale = $this->getJson('/api/sales/recent', $this->h)->json('sales.0');
        $this->assertSame('partially_reversed', $sale['status']);
        $this->assertSame([true, false], array_column($sale['coupons'], 'reversed'));

        // Filas anteriores a la referencia de venta: cada una cuenta como una venta
        DB::table('coupon_transactions')->insert(['coupon_id' => 9, 'coupon_number' => 'OLD123456789', 'transaction_amount' => '7.00', 'coupon_type' => 'QRCODE', 'kind' => 'REDEEM', 'user_id' => $ana->id, 'location_id' => $this->loc->id, 'created_at' => now(), 'updated_at' => now()]);
        $list = $this->getJson('/api/sales/recent?limit=1', $this->h)->assertOk()->assertJsonPath('count', 1);
        $this->assertNull($list->json('sales.0.sale_id'));
        $this->assertSame('*****6789', $list->json('sales.0.coupons.0.coupon_number') ? substr($list->json('sales.0.coupons.0.coupon_number'), -9) : null);

        // Fuera de la ventana de horas no aparece
        DB::table('coupon_transactions')->update(['created_at' => now()->subHours(30)]);
        $this->getJson('/api/sales/recent?hours=24', $this->h)->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/sales/recent?hours=48', $this->h)->assertOk()->assertJsonPath('count', 2);
        $this->getJson('/api/sales/recent?limit=999', $this->h)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }
}
