<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Subfamily;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Support\AdminRbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithDashboard(): AdminUser
    {
        Permission::findOrCreate('dashboard.view', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('dashboard.view');

        return $admin;
    }

    private function paidTransaction(Location $location, float $total): Transaction
    {
        return Transaction::factory()->create([
            'location_id' => $location->id,
            'status' => 'PAID',
            'total' => $total,
            'occurred_at' => now()->subDay(),
        ]);
    }

    private function line(Transaction $tx, ?Product $product, float $total): void
    {
        TransactionItem::factory()->create([
            'transaction_id' => $tx->id,
            'product_id' => $product?->id,
            'product_name' => $product?->name ?? 'Suelto',
            'qty' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);
    }

    public function test_admin_without_permission_sees_welcome_without_figures(): void
    {
        $admin = AdminUser::factory()->create();
        $this->paidTransaction(Location::factory()->create(), 123.45);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard-welcome')
            ->assertDontSee('123.45');
    }

    public function test_admin_with_permission_sees_dashboard(): void
    {
        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewIs('admin.dashboard');
    }

    public function test_dashboard_permission_is_assignable_in_rbac_matrix(): void
    {
        $this->assertSame(['dashboard.view'], AdminRbac::managedPermissionNamesForScreen('dashboard'));
        $this->assertContains('dashboard.view', AdminRbac::allManagedPermissionNames());
        $this->assertContains('dashboard.view', AdminRbac::allCrudPermissionNames());
        $this->assertNotContains('dashboard.edit', AdminRbac::allCrudPermissionNames());
    }

    public function test_location_share_is_over_period_total_not_top(): void
    {
        // 6 localidades: el top 5 no suma el 100 % de lo vendido
        $locations = Location::factory()->count(6)->create();
        foreach ([500, 200, 100, 100, 50, 50] as $i => $total) {
            $this->paidTransaction($locations[$i], $total);
        }

        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertViewHas('topLocations', function (array $top): bool {
                return count($top) === 5
                    && $top[0]['pct'] === 50.0
                    && array_sum(array_column($top, 'pct')) === 95.0;
            });
    }

    public function test_product_share_is_over_period_total_not_top(): void
    {
        $tx = $this->paidTransaction(Location::factory()->create(), 1000);
        foreach ([400, 200, 100, 100, 100, 100] as $total) {
            $this->line($tx, Product::factory()->create(), $total);
        }

        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertViewHas('topProducts', fn (array $top): bool => $top[0]['pct'] === 40.0
                && array_sum(array_column($top, 'pct')) === 90.0);
    }

    public function test_family_mix_includes_others_and_unassigned(): void
    {
        $tx = $this->paidTransaction(Location::factory()->create(), 1000);
        foreach ([300, 200, 100, 100, 100, 50, 50] as $i => $total) {
            $family = Family::create(['name' => 'Fam '.$i]);
            $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Sub '.$i]);
            $this->line($tx, Product::factory()->create(['subfamily_id' => $sub->id]), $total);
        }
        $this->line($tx, Product::factory()->create(['subfamily_id' => null]), 30);
        $this->line($tx, null, 20);

        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertViewHas('chartPayload', function (array $payload): bool {
                $mix = $payload['familyMix'];

                return $mix['labels'] === ['Fam 0', 'Fam 1', 'Fam 2', 'Fam 3', 'Fam 4', 'Otros', 'Sin familia']
                    && $mix['series'][5] === 100.0
                    && $mix['series'][6] === 50.0
                    && array_sum($mix['series']) === 950.0;
            });
    }

    public function test_payment_mix_uses_payment_method_names(): void
    {
        $custom = PaymentMethod::create(['name' => 'Bono regalo', 'type' => 'OTHER']);
        $tx = $this->paidTransaction(Location::factory()->create(), 100);
        TransactionPayment::factory()->create(['transaction_id' => $tx->id, 'payment_method' => $custom->id, 'amount' => 60]);
        TransactionPayment::factory()->create(['transaction_id' => $tx->id, 'payment_method' => 'CASH', 'amount' => 40]);

        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertViewHas('chartPayload', fn (array $payload): bool => $payload['paymentMix']['labels'] === ['Bono regalo', 'Efectivo']);
    }

    private function adminWith(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    public function test_technical_dashboard_lists_issues_in_modals(): void
    {
        $location = Location::factory()->create(['name' => 'Sucursal Sur', 'contingency_started_at' => now()->subHours(3)]);
        Device::factory()->create([
            'location_id' => $location->id,
            'name' => 'Caja Atrasada',
            'is_enabled' => true,
            'last_sync_at' => now()->subHours(6),
        ]);
        $licensed = Device::factory()->create(['location_id' => $location->id, 'name' => 'Caja Por Vencer', 'last_sync_at' => now()]);
        License::factory()->create(['device_id' => $licensed->id, 'valid_to' => now()->addDays(3)]);

        $this->actingAs($this->adminWith('dashboard_technical.view'), 'admin')
            ->get(route('admin.dashboard.technical'))
            ->assertOk()
            ->assertViewIs('admin.dashboard-technical')
            // El KPI abre el modal y el modal trae la lista
            ->assertSee("\$dispatch('open-modal', 'dash-contingencies')", false)
            ->assertSee('Localidades en contingencia (1)')
            ->assertSee('Sucursal Sur')
            ->assertSee("\$dispatch('open-modal', 'dash-devices-no-sync')", false)
            ->assertSee('Caja Atrasada')
            ->assertSee("\$dispatch('open-modal', 'dash-licenses-expiring')", false)
            ->assertSee('Caja Por Vencer');
    }

    public function test_technical_kpis_are_not_clickable_when_nothing_is_wrong(): void
    {
        $location = Location::factory()->create(['contingency_started_at' => null]);
        $device = Device::factory()->create(['location_id' => $location->id, 'is_enabled' => true, 'last_sync_at' => now()->subHour()]);
        License::factory()->create(['device_id' => $device->id, 'valid_to' => now()->addDays(30)]);

        $this->actingAs($this->adminWith('dashboard_technical.view'), 'admin')
            ->get(route('admin.dashboard.technical'))
            ->assertOk()
            ->assertDontSee("\$dispatch('open-modal'", false)
            ->assertViewHas('kpis', fn (array $kpis): bool => collect($kpis)->every(fn ($k) => ($k['tone'] ?? null) !== 'warn'));
    }

    public function test_dashboard_kpis_do_not_repeat_icons(): void
    {
        $admin = $this->adminWith('dashboard.view', 'dashboard_technical.view');

        foreach (['admin.dashboard', 'admin.dashboard.technical'] as $route) {
            $this->actingAs($admin, 'admin')
                ->get(route($route))
                ->assertOk()
                ->assertViewHas('kpis', function (array $kpis): bool {
                    $icons = array_column($kpis, 'icon');

                    return count($icons) === count(array_unique($icons));
                });
        }
    }

    public function test_commercial_dashboard_shows_sales_not_operations(): void
    {
        Location::factory()->create(['name' => 'Sucursal Sur', 'contingency_started_at' => now()->subHours(3)]);
        Transaction::factory()->create(['status' => 'VOIDED', 'total' => 123.45, 'occurred_at' => now()]);

        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Anulaciones hoy')
            ->assertSee('$123.45')
            ->assertDontSee('En contingencia')
            ->assertDontSee('dash-contingencies');
    }

    public function test_admin_with_only_technical_permission_lands_on_technical(): void
    {
        $this->actingAs($this->adminWith('dashboard_technical.view'), 'admin')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.dashboard.technical'));
    }

    public function test_technical_dashboard_requires_its_permission(): void
    {
        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard.technical'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_tabs_only_show_when_both_dashboards_are_allowed(): void
    {
        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard'))
            ->assertDontSee('aria-label="Dashboards"', false);

        $this->actingAs($this->adminWith('dashboard.view', 'dashboard_technical.view'), 'admin')
            ->get(route('admin.dashboard'))
            ->assertSee('aria-label="Dashboards"', false)
            ->assertSee(route('admin.dashboard.technical'));
    }

    public function test_commercial_dashboard_filters_by_location(): void
    {
        $north = Location::factory()->create(['name' => 'Norte']);
        $south = Location::factory()->create(['name' => 'Sur']);
        Transaction::factory()->create(['location_id' => $north->id, 'status' => 'PAID', 'total' => 100, 'occurred_at' => now()]);
        Transaction::factory()->create(['location_id' => $south->id, 'status' => 'PAID', 'total' => 250, 'occurred_at' => now()]);

        $admin = $this->adminWith('dashboard.view', 'dashboard_technical.view');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard', ['location_id' => $north->id]))
            ->assertOk()
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis[0]['value'] === '$100.00')
            ->assertViewHas('topLocations', null)
            ->assertSee('<option value="'.$north->id.'" selected', false)
            // La pestaña técnica conserva el filtro
            ->assertSee(route('admin.dashboard.technical', ['location_id' => $north->id]), false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis[0]['value'] === '$350.00')
            ->assertViewHas('topLocations', fn ($top): bool => count($top) === 2);
    }

    public function test_technical_dashboard_filters_by_location(): void
    {
        $north = Location::factory()->create(['name' => 'Norte', 'contingency_started_at' => now()->subHour()]);
        $south = Location::factory()->create(['name' => 'Sur', 'contingency_started_at' => now()->subHour()]);
        $northDevice = Device::factory()->create(['location_id' => $north->id, 'name' => 'Caja Norte', 'last_sync_at' => now()->subHours(8)]);
        Device::factory()->create(['location_id' => $south->id, 'name' => 'Caja Sur', 'last_sync_at' => now()->subHours(8)]);
        License::factory()->create(['device_id' => $northDevice->id, 'valid_to' => now()->addDays(2)]);

        $this->actingAs($this->adminWith('dashboard_technical.view'), 'admin')
            ->get(route('admin.dashboard.technical', ['location_id' => $north->id]))
            ->assertOk()
            ->assertViewHas('contingencies', fn (array $c): bool => array_column($c, 'name') === ['Norte'])
            ->assertViewHas('devicesNoSync', fn (array $d): bool => array_column($d, 'name') === ['Caja Norte'])
            ->assertViewHas('licensesExpiring', fn (array $l): bool => count($l) === 1)
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis[0]['value'] === '1' && $kpis[2]['value'] === '1');
    }

    public function test_unknown_location_filter_is_ignored(): void
    {
        Transaction::factory()->create(['status' => 'PAID', 'total' => 40, 'occurred_at' => now()]);

        $this->actingAs($this->adminWithDashboard(), 'admin')
            ->get(route('admin.dashboard', ['location_id' => 'no-existe']))
            ->assertOk()
            ->assertViewHas('selectedLocation', null)
            ->assertViewHas('kpis', fn (array $kpis): bool => $kpis[0]['value'] === '$40.00');
    }

    public function test_dashboard_figures_are_cached_per_location_for_a_minute(): void
    {
        $location = Location::factory()->create();
        $admin = $this->adminWithDashboard();
        Transaction::factory()->create(['location_id' => $location->id, 'status' => 'PAID', 'total' => 100, 'occurred_at' => now()]);

        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
            ->assertViewHas('kpis', fn (array $k): bool => $k[0]['value'] === '$100.00')
            ->assertSee('Actualizado');

        // Una venta nueva no aparece hasta que vence la caché
        Transaction::factory()->create(['location_id' => $location->id, 'status' => 'PAID', 'total' => 50, 'occurred_at' => now()]);
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
            ->assertViewHas('kpis', fn (array $k): bool => $k[0]['value'] === '$100.00');

        // Otra localidad usa su propia entrada de caché
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard', ['location_id' => $location->id]))
            ->assertViewHas('kpis', fn (array $k): bool => $k[0]['value'] === '$150.00');

        $this->travel(61)->seconds();
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
            ->assertViewHas('kpis', fn (array $k): bool => $k[0]['value'] === '$150.00');
    }
}
