<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\License;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeviceRemoteBlockTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithEdit(): AdminUser
    {
        Permission::findOrCreate('devices.view', 'admin');
        Permission::findOrCreate('devices.edit', 'admin');
        $admin = AdminUser::factory()->create();
        $admin->givePermissionTo(['devices.view', 'devices.edit']);

        return $admin;
    }

    /** @return array{0: Device, 1: string, 2: License} */
    private function pairedDevice(): array
    {
        $device = Device::factory()->create(['location_id' => Location::factory()->create(['is_active' => true])->id, 'is_enabled' => true]);
        $license = License::factory()->create([
            'device_id' => $device->id,
            'status' => 'ACTIVE',
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
        ]);

        return [$device, $device->createToken('t')->plainTextToken, $license];
    }

    public function test_block_requires_a_reason_and_edit_permission(): void
    {
        [$device] = $this->pairedDevice();

        $viewer = AdminUser::factory()->create();
        $this->actingAs($viewer, 'admin')->post(route('admin.devices.block', $device), ['reason' => 'Tablet robada'])->assertForbidden();

        $this->actingAs($this->adminWithEdit(), 'admin')
            ->post(route('admin.devices.block', $device), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->adminWithEdit(), 'admin')
            ->post(route('admin.devices.block', $device), ['reason' => 'abc'])
            ->assertSessionHasErrors('reason');

        $this->assertTrue($device->fresh()->is_enabled);
    }

    public function test_block_disables_revokes_tokens_and_audits(): void
    {
        [$device, $token] = $this->pairedDevice();
        $admin = $this->adminWithEdit();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.devices.block', $device), ['reason' => 'Tablet extraviada en Centro'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $fresh = $device->fresh();
        $this->assertFalse($fresh->is_enabled);
        $this->assertSame('Tablet extraviada en Centro', $fresh->disabled_reason);
        $this->assertSame($admin->name, $fresh->disabled_by);
        $this->assertNotNull($fresh->disabled_at);
        $this->assertSame(0, $fresh->tokens()->count());

        $audit = AdminAuditLog::where('action', 'blocked')->where('entity_id', $device->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('Tablet extraviada en Centro', $audit->changes['disabled_reason'][1]);
        $this->assertSame(1, $audit->changes['tokens_revoked'][1]);
        // saveQuietly: no duplica el registro con un «updated» genérico
        $this->assertSame(0, AdminAuditLog::where('action', 'updated')->where('entity_id', $device->id)->count());

        // El token revocado ya no sirve
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_blocked_device_gets_a_stable_code_on_login(): void
    {
        [$device, , $license] = $this->pairedDevice();
        $this->actingAs($this->adminWithEdit(), 'admin')->post(route('admin.devices.block', $device), ['reason' => 'Baja del equipo']);
        auth('admin')->logout();

        $this->postJson('/api/auth/login', [
            'device_fingerprint' => $device->device_fingerprint,
            'license_key' => $license->license_key,
        ])
            ->assertForbidden()
            ->assertJson(['code' => 'DEVICE_BLOCKED'])
            ->assertJsonMissing(['Baja del equipo']); // el motivo interno no se expone a la tablet
    }

    public function test_unblock_restores_access_with_reason_and_forces_new_login(): void
    {
        [$device, $token, $license] = $this->pairedDevice();
        $admin = $this->adminWithEdit();
        $this->actingAs($admin, 'admin')->post(route('admin.devices.block', $device), ['reason' => 'Bloqueo de prueba']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.devices.unblock', $device), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.devices.unblock', $device), ['reason' => 'Equipo recuperado'])
            ->assertRedirect();

        $fresh = $device->fresh();
        $this->assertTrue($fresh->is_enabled);
        $this->assertNull($fresh->disabled_reason);
        $this->assertNull($fresh->disabled_at);
        $this->assertNotNull(AdminAuditLog::where('action', 'unblocked')->where('entity_id', $device->id)->first());

        // El token viejo sigue revocado; debe volver a iniciar sesión
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/auth/me')->assertUnauthorized();
        auth('admin')->logout();
        $this->postJson('/api/auth/login', [
            'device_fingerprint' => $device->device_fingerprint,
            'license_key' => $license->license_key,
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_edit_form_cannot_toggle_is_enabled_without_the_block_flow(): void
    {
        [$device] = $this->pairedDevice();

        $this->actingAs($this->adminWithEdit(), 'admin')
            ->put(route('admin.screens.update', ['devices', $device->id]), ['name' => 'Caja 1', 'is_enabled' => '0'])
            ->assertSessionHasErrors('is_enabled');

        $fresh = $device->fresh();
        $this->assertTrue($fresh->is_enabled);
        $this->assertNotSame('Caja 1', $fresh->name);

        // Mismo valor: sigue funcionando (compatibilidad)
        $this->actingAs($this->adminWithEdit(), 'admin')
            ->put(route('admin.screens.update', ['devices', $device->id]), ['name' => 'Caja 1', 'is_enabled' => '1'])
            ->assertRedirect(route('admin.screens.index', 'devices'));
        $this->assertSame('Caja 1', $device->fresh()->name);
    }

    public function test_edit_page_shows_block_form_and_reason_when_blocked(): void
    {
        [$device] = $this->pairedDevice();
        $admin = $this->adminWithEdit();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.screens.edit', ['devices', $device->id]))
            ->assertOk()
            ->assertSee('Motivo del bloqueo')
            ->assertSee('Bloquear y cerrar sesión');

        $this->post(route('admin.devices.block', $device), ['reason' => 'Equipo en reparación']);

        $this->get(route('admin.screens.edit', ['devices', $device->id]))
            ->assertOk()
            ->assertSee('Equipo en reparación')
            ->assertSee('Reactivar dispositivo');
    }
}
