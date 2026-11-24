<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Location;
use App\Support\AdminLocationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LocationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Location $a;

    private Location $b;

    private Location $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Location::factory()->create(['name' => 'Zona Alfa', 'is_active' => true]);
        $this->b = Location::factory()->create(['name' => 'Zona Beta', 'is_active' => true]);
        $this->c = Location::factory()->create(['name' => 'Zona Gamma', 'is_active' => true]);
    }

    private function admin(array $locations = [], string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create(['location_scope' => $locations === [] ? 'all' : 'selected']);
        $admin->locations()->sync(collect($locations)->map(fn ($l) => $l->id)->all());
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'admin');
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    private function tx(Location $loc, string $amount, string $kind = 'REDEEM'): void
    {
        DB::table('coupon_transactions')->insert(['coupon_id' => 1, 'coupon_number' => 'C'.$loc->name, 'transaction_amount' => $amount, 'coupon_type' => 'QRCODE', 'kind' => $kind, 'location_id' => $loc->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function main(TestResponse $res): string
    {
        $html = $res->assertOk()->getContent();

        return substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
    }

    public function test_a_restricted_admin_only_sees_the_records_of_their_locations(): void
    {
        $admin = $this->admin([$this->a], 'locations.view', 'devices.view', 'locations.edit', 'devices.edit', 'coupon_transactions.view');
        $da = Device::factory()->create(['location_id' => $this->a->id, 'name' => 'Caja de Alfa']);
        $db = Device::factory()->create(['location_id' => $this->b->id, 'name' => 'Caja de Beta']);
        $this->tx($this->a, '11.11');
        $this->tx($this->b, '22.22');

        $this->actingAs($admin, 'admin');
        $locations = $this->main($this->get(route('admin.screens.index', 'locations')));
        $this->assertStringContainsString('Zona Alfa', $locations);
        $this->assertStringNotContainsString('Zona Beta', $locations);

        $devices = $this->main($this->get(route('admin.screens.index', 'devices')));
        $this->assertStringContainsString('Caja de Alfa', $devices);
        $this->assertStringNotContainsString('Caja de Beta', $devices);
        $this->get(route('admin.screens.edit', ['devices', $db->id]))->assertNotFound();
        $this->get(route('admin.screens.edit', ['devices', $da->id]))->assertOk();

        $moves = $this->main($this->get(route('admin.screens.index', 'coupon-transactions')));
        $this->assertStringContainsString('11.11', $moves);
        $this->assertStringNotContainsString('22.22', $moves);

        // No crea localidades nuevas ni asigna registros a una fuera de su alcance
        $this->post(route('admin.screens.store', 'locations'), ['name' => 'Otra', 'dispensers' => 1, 'is_active' => 1])->assertForbidden();
    }

    public function test_the_selector_narrows_every_screen_to_the_chosen_location(): void
    {
        $admin = $this->admin([$this->a, $this->b], 'locations.view', 'coupon_transactions.view');
        $this->tx($this->a, '11.11');
        $this->tx($this->b, '22.22');
        $this->actingAs($admin, 'admin');

        // Sin elegir: el conjunto de sus localidades (nunca la tercera)
        $all = $this->main($this->get(route('admin.screens.index', 'coupon-transactions')));
        $this->assertStringContainsString('11.11', $all);
        $this->assertStringContainsString('22.22', $all);

        $this->post(route('admin.location.switch'), ['location_id' => $this->b->id])->assertRedirect();
        $this->assertSame($this->b->id, session(AdminLocationScope::SESSION));
        $beta = $this->main($this->get(route('admin.screens.index', 'coupon-transactions')));
        $this->assertStringContainsString('22.22', $beta);
        $this->assertStringNotContainsString('11.11', $beta);

        // Una localidad fuera de su alcance no se puede elegir
        $this->post(route('admin.location.switch'), ['location_id' => $this->c->id])->assertForbidden();
        $this->post(route('admin.location.switch'), ['location_id' => null])->assertRedirect();
        $this->assertNull(session(AdminLocationScope::SESSION));
    }

    public function test_an_admin_with_all_locations_can_also_narrow_to_one_and_sees_the_selector(): void
    {
        $admin = $this->admin([], 'coupon_transactions.view');
        $this->tx($this->a, '11.11');
        $this->tx($this->c, '33.33');
        $this->actingAs($admin, 'admin');

        $html = $this->get(route('admin.screens.index', 'coupon-transactions'))->assertOk()->assertSee('Todas las localidades')->assertSee('Zona Gamma')->getContent();
        $this->assertStringContainsString('33.33', $html);

        $this->post(route('admin.location.switch'), ['location_id' => $this->a->id]);
        $only = $this->main($this->get(route('admin.screens.index', 'coupon-transactions')));
        $this->assertStringContainsString('11.11', $only);
        $this->assertStringNotContainsString('33.33', $only);
    }

    public function test_reports_are_limited_to_the_permitted_locations_and_ignore_a_foreign_filter(): void
    {
        $admin = $this->admin([$this->a], 'coupon_reports.view', 'coupon_reports.export');
        $this->tx($this->a, '40.00');
        $this->tx($this->b, '900.00');
        $this->actingAs($admin, 'admin');

        $csv = $this->get(route('admin.reports.redemptions', ['export' => 'csv', 'group' => 'location']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Zona Alfa', $csv);
        $this->assertStringNotContainsString('Zona Beta', $csv);
        $this->assertStringNotContainsString('900.00', $csv);

        // Pedir otra localidad por URL no abre nada: rige su alcance
        $forced = $this->get(route('admin.reports.redemptions', ['export' => 'csv', 'group' => 'location', 'location_id' => $this->b->id]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Zona Beta', $forced);
        $page = $this->main($this->get(route('admin.reports.redemptions')));
        $this->assertStringContainsString('Zona Alfa', $page);   // el filtro de localidad lista solo las suyas
        $this->assertStringNotContainsString('Zona Gamma', $page);
    }

    public function test_the_super_admin_assigns_all_or_selected_locations_when_creating_an_admin_user(): void
    {
        $super = AdminUser::factory()->create();
        $super->assignRole(Role::findOrCreate('super-admin', 'admin'));
        Role::findOrCreate('operador', 'admin');
        $this->actingAs($super, 'admin');
        $base = ['name' => 'Ana Operadora', 'email' => 'ana@example.com', 'password' => 'Clave-Muy-Larga-1!', 'role_names' => ['operador'], 'is_active' => 1];

        // «Seleccionadas» exige al menos una
        $this->post(route('admin.screens.store', 'admin-users'), $base + ['location_scope' => 'selected'])->assertSessionHasErrors('location_ids');
        $this->post(route('admin.screens.store', 'admin-users'), $base + ['location_scope' => 'selected', 'location_ids' => [$this->a->id, $this->b->id]])->assertSessionHasNoErrors();

        $ana = AdminUser::where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame('selected', $ana->location_scope);
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $ana->allowedLocationIds());
        $this->assertDatabaseHas('admin_audit_logs', ['entity_type' => 'AdminUser', 'entity_id' => (string) $ana->id, 'action' => 'updated']);

        // Pasarla a «todas» limpia la lista
        $this->put(route('admin.screens.update', ['admin-users', $ana->id]), ['name' => 'Ana Operadora', 'email' => 'ana@example.com', 'role_names' => ['operador'], 'is_active' => 1, 'location_scope' => 'all'])->assertSessionHasNoErrors();
        $this->assertNull($ana->fresh()->allowedLocationIds());
        $this->assertSame(0, $ana->locations()->count());

        // El super-admin nunca está restringido
        $super->forceFill(['location_scope' => 'selected'])->save();
        $this->assertNull($super->fresh()->allowedLocationIds());
    }

    public function test_a_restricted_admin_cannot_assign_a_record_to_a_foreign_location(): void
    {
        $admin = $this->admin([$this->a], 'android_users.view', 'android_users.edit');
        $this->actingAs($admin, 'admin');

        $this->post(route('admin.screens.store', 'android-users'), ['username' => 'caja1', 'password' => 'Clave-Corta1', 'role' => 'CASHIER', 'is_active' => 1, 'location_id' => $this->b->id])->assertSessionHasErrors('location_id');
        $this->post(route('admin.screens.store', 'android-users'), ['username' => 'caja2', 'password' => 'Clave-Corta1', 'role' => 'CASHIER', 'is_active' => 1, 'location_id' => $this->a->id])->assertSessionHasNoErrors();
    }
}
