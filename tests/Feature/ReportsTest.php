<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'products_best_sellers.view',
        'payment_methods_report.view',
        'users_performance_report.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'admin']);
        }
    }

    protected function createAdminUser()
    {
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo(self::PERMISSIONS);

        return $admin;
    }

    public function test_products_best_sellers_route_returns_ok()
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')
            ->get('/admin/reports/products-best-sellers')
            ->assertOk()
            ->assertViewIs('admin.reports.products-best-sellers');
    }

    public function test_products_best_sellers_displays_data()
    {
        $admin = $this->createAdminUser();
        $location = Location::factory()->create();
        $product = Product::factory()->create(['name' => 'Test Product', 'sku' => 'TEST-001']);
        $user = User::factory()->create();

        $transaction = Transaction::factory()->create([
            'location_id' => $location->id,
            'user_id' => $user->id,
            'occurred_at' => now(),
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'qty' => 5,
            'unit_price' => 20.00,
            'line_total' => 100.00,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/reports/products-best-sellers')
            ->assertOk()
            ->assertSee('Test Product')
            ->assertSee('TEST-001');
    }

    public function test_payment_methods_report_route_returns_ok()
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')
            ->get('/admin/reports/payment-methods')
            ->assertOk()
            ->assertViewIs('admin.reports.payment-methods');
    }

    public function test_payment_methods_displays_data()
    {
        $admin = $this->createAdminUser();
        $location = Location::factory()->create();
        $user = User::factory()->create();

        $transaction = Transaction::factory()->create([
            'location_id' => $location->id,
            'user_id' => $user->id,
            'occurred_at' => now(),
        ]);

        TransactionPayment::factory()->create([
            'transaction_id' => $transaction->id,
            'payment_method' => 'Credit Card',
            'amount' => 150.00,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/reports/payment-methods')
            ->assertOk()
            ->assertSee('Credit Card');
    }

    public function test_users_performance_report_route_returns_ok()
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')
            ->get('/admin/reports/users-performance')
            ->assertOk()
            ->assertViewIs('admin.reports.users-performance');
    }

    public function test_users_performance_displays_data()
    {
        $admin = $this->createAdminUser();
        $location = Location::factory()->create();
        $user = User::factory()->create(['full_name' => 'John Doe', 'username' => 'johndoe']);

        Transaction::factory()->create([
            'location_id' => $location->id,
            'user_id' => $user->id,
            'total' => 500.00,
            'occurred_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/reports/users-performance')
            ->assertOk()
            ->assertSee('John Doe')
            ->assertSee('johndoe');
    }

    public function test_products_best_sellers_accepts_date_filters()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin, 'admin')
            ->get('/admin/reports/products-best-sellers?date_from=2026-01-01&date_to=2026-12-31')
            ->assertOk();

        $this->assertStringContainsString('2026-01-01', $response->content());
        $this->assertStringContainsString('2026-12-31', $response->content());
    }

    public function test_users_performance_accepts_location_filter()
    {
        $admin = $this->createAdminUser();
        $location = Location::factory()->create();

        $response = $this->actingAs($admin, 'admin')
            ->get('/admin/reports/users-performance?location_id='.$location->id)
            ->assertOk();

        $this->assertStringContainsString($location->id, $response->content());
    }

    public function test_report_pages_require_permission()
    {
        $admin = AdminUser::factory()->create();

        $this->actingAs($admin, 'admin')->get('/admin/reports/products-best-sellers')->assertForbidden();
        $this->actingAs($admin, 'admin')->get('/admin/reports/payment-methods')->assertForbidden();
        $this->actingAs($admin, 'admin')->get('/admin/reports/users-performance')->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->post('/admin/reports/products-best-sellers/export-csv', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'])
            ->assertForbidden();
    }

    public function test_generic_screen_route_redirects_to_report_page()
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')
            ->get('/admin/screens/products-best-sellers')
            ->assertRedirect(route('admin.reports.products-best-sellers'));
    }

    public static function exportProvider(): array
    {
        $out = [];
        foreach (['products-best-sellers', 'payment-methods', 'users-performance'] as $report) {
            $out["$report excel"] = [$report, 'export', 'spreadsheetml'];
            $out["$report csv"] = [$report, 'export-csv', 'text/csv'];
            $out["$report pdf"] = [$report, 'export-pdf', 'application/pdf'];
        }

        return $out;
    }

    #[DataProvider('exportProvider')]
    public function test_exports_download_file(string $report, string $action, string $contentType)
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['full_name' => 'Ana <b>Test</b>']);
        $transaction = Transaction::factory()->create(['user_id' => $user->id, 'occurred_at' => now()->subDay()]);
        TransactionItem::factory()->create(['transaction_id' => $transaction->id]);
        TransactionPayment::factory()->create(['transaction_id' => $transaction->id, 'created_at' => now()->subDay()]);

        $response = $this->actingAs($admin, 'admin')->post("/admin/reports/$report/$action", [
            'date_from' => now()->subWeek()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $response->assertOk();
        $this->assertStringContainsString($contentType, $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->streamedContent());
    }

    public function test_export_validates_dates()
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/reports/payment-methods/export-pdf', [])
            ->assertStatus(422);
    }

    /** Venta con una línea y un pago, para los reportes. */
    private function sale(string $status, float $total, \DateTimeInterface $at, ?Location $location = null, string $method = 'CASH'): Transaction
    {
        $location ??= Location::factory()->create();
        $product = Product::factory()->create(['name' => 'Prod '.$status.' '.$total]);
        $tx = Transaction::factory()->create([
            'location_id' => $location->id,
            'status' => $status,
            'total' => $total,
            'occurred_at' => $at,
        ]);
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);
        TransactionPayment::factory()->create(['transaction_id' => $tx->id, 'payment_method' => $method, 'amount' => $total]);

        return $tx;
    }

    public function test_reports_only_count_paid_sales(): void
    {
        $this->sale('PAID', 100, now()->subDay());
        $this->sale('VOIDED', 500, now()->subDay());
        $this->sale('PENDING', 70, now()->subDay());
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')->get('/admin/reports/products-best-sellers')
            ->assertViewHas('products', fn ($rows) => (float) $rows->sum('total_revenue') === 100.0);
        $this->actingAs($admin, 'admin')->get('/admin/reports/payment-methods')
            ->assertViewHas('methods', fn ($rows) => (float) $rows->sum('total_amount') === 100.0
                && (int) $rows->sum('total_transactions') === 1);
        $this->actingAs($admin, 'admin')->get('/admin/reports/users-performance')
            ->assertViewHas('users', fn ($rows) => (float) $rows->sum('total_sales') === 100.0);
    }

    public function test_date_to_includes_the_whole_last_day(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->sale('PAID', 40, now()->setTime(21, 30));
        $this->sale('PAID', 60, now()->subDays(3));
        $range = ['date_from' => now()->subDays(3)->toDateString(), 'date_to' => now()->toDateString()];
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')->get('/admin/reports/products-best-sellers?'.http_build_query($range))
            ->assertViewHas('products', fn ($rows) => (float) $rows->sum('total_revenue') === 100.0);

        $csv = $this->actingAs($admin, 'admin')
            ->post('/admin/reports/payment-methods/export-csv', $range)
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('100', $csv);
    }

    public function test_payment_report_uses_sale_date_and_location(): void
    {
        $north = Location::factory()->create();
        $south = Location::factory()->create();
        // Venta de hace 40 días sincronizada hoy: queda fuera del último mes
        $old = $this->sale('PAID', 900, now()->subDays(40), $north);
        TransactionPayment::where('transaction_id', $old->id)->update(['created_at' => now()]);
        $this->sale('PAID', 30, now()->subDay(), $north, 'CARD');
        $this->sale('PAID', 20, now()->subDay(), $south, 'CARD');
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'admin')->get('/admin/reports/payment-methods')
            ->assertViewHas('methods', fn ($rows) => (float) $rows->sum('total_amount') === 50.0);
        $this->actingAs($admin, 'admin')->get('/admin/reports/payment-methods?location_id='.$north->id)
            ->assertViewHas('methods', fn ($rows) => (float) $rows->sum('total_amount') === 30.0);

        $csv = $this->actingAs($admin, 'admin')
            ->post('/admin/reports/payment-methods/export-csv', [
                'date_from' => now()->subMonth()->toDateString(),
                'date_to' => now()->toDateString(),
                'location_id' => $north->id,
            ])
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('30', $csv);
        $this->assertStringNotContainsString('50', $csv);
    }

    public function test_invalid_dates_fall_back_to_last_month(): void
    {
        $this->actingAs($this->createAdminUser(), 'admin')
            ->get('/admin/reports/products-best-sellers?date_from=nope&date_to=2026-13-45')
            ->assertOk()
            ->assertViewHas('dateTo', now()->toDateString());
    }
}
