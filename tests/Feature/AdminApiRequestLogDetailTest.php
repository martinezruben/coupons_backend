<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\ApiRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminApiRequestLogDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_api_request_log_detail(): void
    {
        $log = ApiRequestLog::create([
            'method' => 'GET',
            'path' => '/api/x',
            'parameters' => null,
            'response_status' => 200,
            'response_summary' => null,
        ]);

        $this->get(route('admin.api-request-logs.show', $log->getKey()))
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_with_permission_receives_formatted_json_fields(): void
    {
        Permission::create(['name' => 'api_request_logs.view', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'log-viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo('api_request_logs.view');

        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        $log = ApiRequestLog::create([
            'method' => 'POST',
            'path' => '/api/auth/login',
            'parameters' => '{"device_fingerprint":"fp"}',
            'response_status' => 200,
            'response_summary' => '{"token":"abc"}',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.api-request-logs.show', $log->getKey()));

        $response->assertOk()
            ->assertJsonPath('method', 'POST')
            ->assertJsonPath('path', '/api/auth/login')
            ->assertJsonStructure([
                'id',
                'parameters',
                'parameters_json',
                'response_summary',
                'response_json',
                'response_status',
            ]);

        $this->assertStringContainsString('"device_fingerprint"', $response->json('parameters_json'));
        $this->assertStringContainsString('"token"', $response->json('response_json'));
    }

    public function test_admin_without_permission_gets_forbidden(): void
    {
        Role::create(['name' => 'empty', 'guard_name' => 'admin']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole('empty');

        $log = ApiRequestLog::create([
            'method' => 'GET',
            'path' => '/api/x',
            'parameters' => null,
            'response_status' => 200,
            'response_summary' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.api-request-logs.show', $log->getKey()))
            ->assertForbidden();
    }
}
