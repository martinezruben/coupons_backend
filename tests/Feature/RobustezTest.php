<?php

namespace Tests\Feature;

use App\Console\Commands\PruneLogs;
use App\Models\AdminAuditLog;
use App\Models\ApiRequestLog;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
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

        $old = ApiRequestLog::create([
            'device_id' => $device->id,
            'location_id' => $device->location_id,
            'method' => 'GET',
            'path' => '/api/auth/me',
            'response_status' => 200,
        ]);
        ApiRequestLog::create([
            'device_id' => $device->id,
            'location_id' => $device->location_id,
            'method' => 'GET',
            'path' => '/api/auth/me',
            'response_status' => 200,
        ]);
        // created_at no es fillable: forzar fecha vieja por query builder
        DB::table('api_request_logs')
            ->where('id', $old->id)
            ->update(['created_at' => now()->subDays(120)]);

        $this->artisan(PruneLogs::class, ['--days' => 90])
            ->expectsOutputToContain('api_request_logs')
            ->assertSuccessful();

        $this->assertDatabaseCount('api_request_logs', 1);
    }

    public function test_audit_log_has_table_for_pruning(): void
    {
        AdminAuditLog::record('created', 'Product', (string) Str::uuid());
        $this->assertDatabaseCount('admin_audit_logs', 1);
    }
}
