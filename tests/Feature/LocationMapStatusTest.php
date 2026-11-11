<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Location;
use App\Services\DashboardService;
use App\Support\LocationMapSyncPins;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocationMapStatusTest extends TestCase
{
    use RefreshDatabase;

    private function pins(): array
    {
        return collect(LocationMapSyncPins::forRequest(Request::create('/admin/screens/locations'), false))
            ->keyBy('name')
            ->all();
    }

    public function test_status_priority_and_four_hour_threshold(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $hours = DashboardService::STALE_SYNC_HOURS;

        $online = Location::factory()->create(['name' => 'Online']);
        Device::factory()->create(['location_id' => $online->id, 'is_enabled' => true, 'last_sync_at' => now()->subHours($hours)->addMinute()]);

        $stale = Location::factory()->create(['name' => 'Stale']);
        Device::factory()->create(['location_id' => $stale->id, 'is_enabled' => true, 'last_sync_at' => now()->subHours($hours)->subMinute()]);

        $never = Location::factory()->create(['name' => 'Nunca']);
        Device::factory()->create(['location_id' => $never->id, 'is_enabled' => true, 'last_sync_at' => null]);

        $noDevices = Location::factory()->create(['name' => 'SinEquipos']);

        // Contingencia gana a «en línea»; inactiva gana a todo
        $cont = Location::factory()->create(['name' => 'Cont', 'contingency_started_at' => now()->subHour()]);
        Device::factory()->create(['location_id' => $cont->id, 'is_enabled' => true, 'last_sync_at' => now()]);
        $inactive = Location::factory()->create(['name' => 'Inactiva', 'is_active' => false, 'contingency_started_at' => now()->subHour()]);

        // Un dispositivo deshabilitado no cuenta como «en línea»
        $disabled = Location::factory()->create(['name' => 'Deshabilitado']);
        Device::factory()->create(['location_id' => $disabled->id, 'is_enabled' => false, 'last_sync_at' => now()]);

        $pins = $this->pins();

        $this->assertSame('online', $pins['Online']['status']);
        $this->assertSame('stale', $pins['Stale']['status']);
        $this->assertSame(1, $pins['Stale']['staleDevices']);
        $this->assertSame('stale', $pins['Nunca']['status']);
        $this->assertSame('stale', $pins['SinEquipos']['status']);
        $this->assertSame('Sin dispositivos activos', $pins['SinEquipos']['statusLabel']);
        $this->assertSame('contingency', $pins['Cont']['status']);
        $this->assertSame('inactive', $pins['Inactiva']['status']);
        $this->assertSame('stale', $pins['Deshabilitado']['status']);
    }

    public function test_last_sync_uses_the_most_recent_device_sync(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $loc = Location::factory()->create(['name' => 'Sede', 'last_sync_at' => null]);
        Device::factory()->create(['location_id' => $loc->id, 'is_enabled' => true, 'last_sync_at' => now()->subHours(2)]);
        Device::factory()->create(['location_id' => $loc->id, 'is_enabled' => true, 'last_sync_at' => now()->subMinutes(10)]);

        $this->assertStringContainsString('10 minutos', $this->pins()['Sede']['lastSyncHuman']);
    }

    public function test_map_status_matches_the_technical_dashboard_definition(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 12:00:00'));
        $loc = Location::factory()->create(['name' => 'Sede']);
        Device::factory()->create(['location_id' => $loc->id, 'is_enabled' => true, 'last_sync_at' => now()->subHours(5)]);

        $notSynced = (new DashboardService)->getDevicesNotSyncedSince();

        $this->assertCount(1, $notSynced);
        $this->assertSame('stale', $this->pins()['Sede']['status']);
    }

    public function test_status_endpoint_requires_permission_and_returns_pins(): void
    {
        Location::factory()->create(['name' => 'Sede Norte']);

        $this->getJson('/admin/locations-map/status')->assertUnauthorized();

        $plain = AdminUser::factory()->create();
        $this->actingAs($plain, 'admin')->getJson('/admin/locations-map/status')->assertForbidden();

        Permission::findOrCreate('locations.view', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('locations.view');

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/locations-map/status')
            ->assertOk()
            ->assertJsonPath('0.name', 'Sede Norte')
            ->assertJsonPath('0.status', 'stale');
    }

    public function test_locations_grid_renders_legend_and_list(): void
    {
        Location::factory()->create(['name' => 'Sede Sur']);
        Permission::findOrCreate('locations.view', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('locations.view');

        $this->actingAs($admin, 'admin')
            ->get('/admin/screens/locations')
            ->assertOk()
            ->assertSee('data-map-filter="contingency"', false)
            ->assertSee('data-status-url', false)
            ->assertSee('Sede Sur')
            ->assertSee('Ver estado en lista');
    }
}
