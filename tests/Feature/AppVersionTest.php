<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\SystemParameter;
use App\Services\DashboardService;
use App\Support\AppVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    private function pairedDevice(array $deviceAttrs = []): array
    {
        $device = Device::factory()->create($deviceAttrs + [
            'location_id' => Location::factory()->create(['is_active' => true])->id,
            'is_enabled' => true,
        ]);
        $license = License::factory()->create([
            'device_id' => $device->id,
            'status' => 'ACTIVE',
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
        ]);

        return [$device, $device->createToken('t')->plainTextToken, $license];
    }

    public function test_normalize_and_semantic_comparison(): void
    {
        $this->assertSame('1.4.2', AppVersion::normalize(' 1.4.2 '));
        $this->assertSame('2', AppVersion::normalize('2'));
        $this->assertSame('1.4.2-debug', AppVersion::normalize('1.4.2-debug'));
        $this->assertNull(AppVersion::normalize('v1.4'));
        $this->assertNull(AppVersion::normalize('1.4.x'));
        $this->assertNull(AppVersion::normalize(''));
        $this->assertNull(AppVersion::normalize(str_repeat('1', 40)));
        $this->assertNull(AppVersion::normalize(['1.0']));

        $this->assertTrue(AppVersion::isOutdated('1.9.0', '1.10.0'));   // no es comparación de texto
        $this->assertFalse(AppVersion::isOutdated('1.10.0', '1.9.0'));
        $this->assertFalse(AppVersion::isOutdated('1.4.0', '1.4.0'));
        $this->assertFalse(AppVersion::isOutdated('1.4.0-debug', '1.4.0'));
        $this->assertFalse(AppVersion::isOutdated(null, '1.4.0'));      // sin versión reportada no se marca
        $this->assertFalse(AppVersion::isOutdated('1.0.0', null));      // sin mínima no se marca
    }

    public function test_version_header_is_recorded_on_authenticated_calls_and_login(): void
    {
        [$device, $token, $license] = $this->pairedDevice();

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'App-Version' => '1.4.2'])
            ->getJson('/api/auth/me')->assertOk();
        $this->assertSame('1.4.2', $device->fresh()->app_version);
        $this->assertNotNull($device->fresh()->app_version_seen_at);

        $this->postJson('/api/auth/login', [
            'device_fingerprint' => $device->device_fingerprint,
            'license_key' => $license->license_key,
        ], ['App-Version' => '1.5.0'])->assertOk();
        $this->assertSame('1.5.0', $device->fresh()->app_version);
    }

    public function test_invalid_or_missing_header_never_overwrites_the_stored_version(): void
    {
        [$device, $token] = $this->pairedDevice(['app_version' => '1.4.2', 'app_version_seen_at' => now()]);
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($auth + ['App-Version' => 'basura'])->getJson('/api/auth/me')->assertOk();
        $this->withHeaders($auth)->getJson('/api/auth/me')->assertOk();

        $this->assertSame('1.4.2', $device->fresh()->app_version);
    }

    public function test_seen_at_is_only_refreshed_after_hours_when_version_is_unchanged(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
        [$device, $token] = $this->pairedDevice(['app_version' => '1.4.2', 'app_version_seen_at' => now()->subHour()]);
        $headers = ['Authorization' => 'Bearer '.$token, 'App-Version' => '1.4.2'];

        $this->withHeaders($headers)->getJson('/api/auth/me')->assertOk();
        $this->assertTrue($device->fresh()->app_version_seen_at->equalTo(now()->subHour()));

        $this->travel(7)->hours();
        $this->withHeaders($headers)->getJson('/api/auth/me')->assertOk();
        $this->assertTrue($device->fresh()->app_version_seen_at->equalTo(now()));
    }

    public function test_outdated_devices_for_the_dashboard(): void
    {
        SystemParameter::query()->update(['min_app_version' => '1.4.0']);
        $loc = Location::factory()->create();
        Device::factory()->create(['location_id' => $loc->id, 'name' => 'Vieja', 'app_version' => '1.3.9']);
        Device::factory()->create(['location_id' => $loc->id, 'name' => 'Al dia', 'app_version' => '1.10.0']);
        Device::factory()->create(['location_id' => $loc->id, 'name' => 'Sin reportar', 'app_version' => null]);
        Device::factory()->create(['location_id' => $loc->id, 'name' => 'Bloqueada', 'app_version' => '1.0.0', 'is_enabled' => false]);

        $rows = (new DashboardService)->getOutdatedDevices();

        $this->assertSame(['Vieja'], array_column($rows, 'name'));
        $this->assertSame('1.4.0', $rows[0]['minimum']);

        SystemParameter::query()->update(['min_app_version' => null]);
        $this->assertSame([], (new DashboardService)->getOutdatedDevices());
    }

    public function test_technical_dashboard_shows_the_card_only_when_a_minimum_is_configured(): void
    {
        Permission::findOrCreate('dashboard_technical.view', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo('dashboard_technical.view');
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.dashboard.technical'))
            ->assertOk()
            ->assertViewHas('kpis', fn (array $k): bool => ! collect($k)->contains(fn ($c) => str_starts_with($c['label'], 'App desactualizada')));

        SystemParameter::query()->update(['min_app_version' => '2.0.0']);
        Device::factory()->create(['app_version' => '1.0.0']);
        Cache::flush();

        $this->get(route('admin.dashboard.technical'))
            ->assertOk()
            ->assertViewHas('kpis', fn (array $k): bool => collect($k)->contains(fn ($c) => $c['label'] === 'App desactualizada (< 2.0.0)' && $c['value'] === '1'))
            ->assertSee('dash-outdated-devices', false);
    }

    public function test_min_version_setting_validation(): void
    {
        Permission::findOrCreate('system_settings.view', 'admin');
        Permission::findOrCreate('system_settings.edit', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo(['system_settings.view', 'system_settings.edit']);
        $params = SystemParameter::query()->first();

        $base = [
            'admin_password_min_length' => 8, 'pos_password_min_length' => 4,
            'admin_max_failed_login_attempts' => 5, 'admin_lockout_minutes' => 15,
        ];

        $this->actingAs($admin, 'admin')->put(route('admin.system-settings.update'), $base + ['min_app_version' => 'v1'])
            ->assertSessionHasErrors('min_app_version');

        $this->actingAs($admin, 'admin')->put(route('admin.system-settings.update'), $base + ['min_app_version' => '1.4.0'])
            ->assertSessionHasNoErrors();
        $this->assertSame('1.4.0', $params->fresh()->min_app_version);

        $this->actingAs($admin, 'admin')->put(route('admin.system-settings.update'), $base + ['min_app_version' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($params->fresh()->min_app_version);
    }
}
