<?php

namespace Tests\Feature;

use App\Console\Commands\PruneLogs;
use App\Models\AdminAuditLog;
use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use App\Models\SyncLog;
use App\Models\SystemParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RobustezTest extends TestCase
{
    use RefreshDatabase;

    private function deviceWithLicense(string $fingerprint): Device
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => $fingerprint,
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        return $device;
    }

    public function test_prune_logs_deletes_only_old_rows(): void
    {
        $device = $this->deviceWithLicense('fp-prune');

        SyncLog::create([
            'location_id' => $device->location_id,
            'device_id' => $device->id,
            'operation' => 'PULL',
            'entity' => 'catalog',
            'records_count' => 1,
            'status' => 'SUCCESS',
            'started_at' => now()->subDays(120),
            'finished_at' => now()->subDays(120),
        ]);
        SyncLog::create([
            'location_id' => $device->location_id,
            'device_id' => $device->id,
            'operation' => 'PULL',
            'entity' => 'catalog',
            'records_count' => 1,
            'status' => 'SUCCESS',
            'started_at' => now()->subDays(1),
            'finished_at' => now()->subDays(1),
        ]);
        $apiLog = ApiRequestLog::create([
            'device_id' => $device->id,
            'location_id' => $device->location_id,
            'method' => 'GET',
            'path' => '/api/sync/pull',
            'response_status' => 200,
        ]);
        // created_at no es fillable: forzar fecha vieja por query builder
        DB::table('api_request_logs')
            ->where('id', $apiLog->id)
            ->update(['created_at' => now()->subDays(120)]);

        $this->artisan(PruneLogs::class, ['--days' => 90])
            ->expectsOutputToContain('sync_logs')
            ->assertSuccessful();

        $this->assertDatabaseCount('sync_logs', 1);
        $this->assertDatabaseCount('api_request_logs', 0);
    }

    public function test_sync_paused_returns_503_only_for_sync_routes(): void
    {
        $device = $this->deviceWithLicense('fp-paused');
        $params = SystemParameter::query()->firstOrFail();
        $params->update(['sync_paused' => true]);

        $token = $device->createToken('paused')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        // sync/pull → 503 SYNC_PAUSED
        $this->withHeaders($headers)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertStatus(503)
            ->assertJsonPath('error', 'SYNC_PAUSED');

        // sync/push → 503
        $this->withHeaders($headers)
            ->postJson('/api/sync/push', ['transactions' => []])
            ->assertStatus(503);

        // auth/me sigue operativo
        $this->withHeaders($headers)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_sync_works_when_not_paused(): void
    {
        $device = $this->deviceWithLicense('fp-not-paused');
        SystemParameter::query()->firstOrFail()->update(['sync_paused' => false]);

        $token = $device->createToken('not-paused')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk();
    }

    public function test_audit_log_has_table_for_pruning(): void
    {
        AdminAuditLog::record('created', 'Product', (string) Str::uuid());
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }
}
