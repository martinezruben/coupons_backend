<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Family;
use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FamilyImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        Role::findOrCreate('super-admin', 'admin');
        $admin = AdminUser::factory()->create(['is_active' => true]);
        $admin->assignRole('super-admin');

        return $admin;
    }

    /**
     * Valida upload de imagen al crear/editar familias + productos:
     *  - la imagen se guarda en storage/app/public
     *  - la url se persiste en image_url
     *  - el grid refleja la miniatura
     */
    public function test_create_family_saves_image_url(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin(), 'admin');

        $response = $this->post(route('admin.screens.store', 'families'), [
            'name' => 'Familia con imagen',
            'family_image' => File::image('family_image.png', 32, 32),
        ]);

        $response->assertRedirect(route('admin.screens.index', 'families'));

        $fam = Family::where('name', 'Familia con imagen')->first();
        $this->assertNotNull($fam);
        $this->assertNotEmpty($fam->image_url);

        $grid = $this->get(route('admin.screens.index', 'families'))->getContent();
        // el grid muestra la miniatura WebP generada junto a la imagen
        $thumb = dirname($fam->image_url).'/thumbs/'.pathinfo($fam->image_url, PATHINFO_FILENAME).'.webp';
        $this->assertStringContainsString($thumb, $grid);
    }

    /**
     * Valida el toggle de status de licenses (ACTIVE ↔ INACTIVE).
     */
    public function test_toggle_status_changes_license_state(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
        $this->withoutExceptionHandling();

        $license = License::factory()->create(['status' => 'ACTIVE']);

        // forzar estado ACTIVE antes del toggle para determinismo
        $license->forceFill(['status' => 'ACTIVE'])->save();
        $license->refresh();
        $this->assertEquals('ACTIVE', $license->status);

        $response = $this->post(route('admin.screens.toggle-status', ['licenses', $license->id]));
        $response->assertStatus(302);

        $license->refresh();
        $this->assertEquals('INACTIVE', $license->status);

        // toggle de vuelta a ACTIVE
        $this->post(route('admin.screens.toggle-status', ['licenses', $license->id]));
        $license->refresh();
        $this->assertEquals('ACTIVE', $license->status);
    }

    /**
     * EXPIRED/REVOKED no deben cambiar vía toggle (estados finales).
     */
    public function test_toggle_ignores_expired_revoked(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        // Usar cualquier license existente y forzar estado final
        $license = License::factory()->create();

        // Forzar un estado final (EXPIRED) e intentar toggle → no debe cambiar
        $license->forceFill(['status' => 'EXPIRED'])->save();
        $license->refresh();
        $original = $license->status;

        $this->post(route('admin.screens.toggle-status', ['licenses', $license->id]))
            ->assertStatus(302);

        $license->refresh();
        $this->assertEquals($original, $license->status, "$original debe mantenerse inmutable vía toggle");

    }
}
