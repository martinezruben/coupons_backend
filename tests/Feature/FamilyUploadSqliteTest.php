<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Family;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FamilyUploadSqliteTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_http_upload_flow_stores_filename_url(): void
    {
        Storage::fake('public');
        $admin = AdminUser::factory()->create(['is_active' => true]);
        if (! Role::where('name', 'super-admin')->exists()) {
            Role::create(['name' => 'super-admin', 'guard_name' => 'admin']);
        }
        $admin->assignRole('super-admin');
        $this->actingAs($admin, 'admin');

        $res = $this->post(route('admin.screens.store', 'families'), [
            'name' => 'Familia Diag',
            'family_image' => File::image('family.jpg', 800, 600, 'image/jpeg'),
        ]);

        $res->assertRedirect();

        $fam = Family::where('name', 'Familia Diag')->first();
        $this->assertNotNull($fam, 'La familia debe crearse');
        fwrite(STDERR, "\nimage_url guardada: ".var_export($fam->image_url, true)."\n");
        $this->assertNotEmpty($fam->image_url);
        $this->assertStringEndsWith('.jpg', $fam->image_url);
    }
}
