<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Support\AdminNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string ...$permissions): AdminUser
    {
        $admin = AdminUser::factory()->create();
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin');
            $admin->givePermissionTo($name);
        }

        return $admin;
    }

    public function test_menu_items_have_distinct_icons(): void
    {
        $admin = AdminUser::factory()->create();
        $admin->assignRole(Role::findOrCreate('super-admin', 'admin'));
        $this->actingAs($admin, 'admin');

        $groups = AdminNavigation::groups($admin);
        $icons = collect($groups)->pluck('items')->flatten(1)->pluck('icon');

        $this->assertNotEmpty($icons);
        $this->assertSame($icons->count(), $icons->unique()->count(), 'Dos páginas del menú comparten ícono: '.$icons->duplicates()->implode(', '));
        $this->assertSame(count($groups), collect($groups)->pluck('icon')->unique()->count());
    }

    public function test_search_entries_follow_permissions(): void
    {
        $admin = $this->admin('locations.view');
        $this->actingAs($admin, 'admin');

        $labels = array_column(AdminNavigation::searchEntries($admin), 'label');

        $this->assertContains('Inicio', $labels);
        $this->assertContains('Localidades', $labels);
        $this->assertNotContains('Dispositivos', $labels);
        $this->assertNotContains('Parámetros del sistema', $labels);

        $locations = collect(AdminNavigation::searchEntries($admin))->firstWhere('label', 'Localidades');
        $this->assertTrue($locations['searchable']);
        $this->assertSame(1, $locations['searchRank']);
    }

    public function test_sidebar_and_palette_render_the_same_pages(): void
    {
        $this->actingAs($this->admin('locations.view'), 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.screens.index', 'locations'))
            ->assertDontSee(route('admin.screens.index', 'devices'))
            ->assertSee('adminPalette(', false)
            ->assertDontSee('disabled aria-disabled="true"', false);
    }

    public function test_horizontal_menu_follows_the_design_system_rules(): void
    {
        $admin = AdminUser::factory()->create();
        $admin->assignRole(Role::findOrCreate('super-admin', 'admin'));

        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

        // Máximo 6 elementos de primer nivel (Inicio + grupos), sin sidebar
        $this->assertLessThanOrEqual(6, preg_match_all('/class="hmenu-item"/', $html));
        $this->assertGreaterThanOrEqual(2, preg_match_all('/class="hmenu-item"/', $html));
        $this->assertStringNotContainsString('<aside', $html);
        // Los desplegables se abren con clic y avisan su estado a los lectores de pantalla
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('hmenu-collapse', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        // Sin fuentes web externas
        $this->assertStringNotContainsString('fonts.googleapis', $html);
        $this->assertStringNotContainsString('fonts.bunny', $html);
        // Un grupo de 6 enlaces usa la variante de dos columnas con descripciones
        $this->assertStringContainsString('is-mega', $html);
        $this->assertStringContainsString('Cobrado, reversado y recargado por periodo', $html);
    }
}
