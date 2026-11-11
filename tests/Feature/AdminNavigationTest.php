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
}
