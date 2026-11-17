<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Support\AdminNavigation;
use App\Support\MenuStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MenuEditorTest extends TestCase
{
    use RefreshDatabase;

    private function super(): AdminUser
    {
        $a = AdminUser::factory()->create();
        $a->assignRole(Role::findOrCreate('super-admin', 'admin'));

        return $a;
    }

    private function save(array $groups)
    {
        return $this->put(route('admin.menu.update'), ['payload' => json_encode(['groups' => $groups])]);
    }

    /** Estructura actual lista para enviar (como la manda el editor). */
    private function current(): array
    {
        return array_map(fn ($g) => $g, MenuStructure::load());
    }

    public function test_the_menu_is_seeded_from_the_current_structure(): void
    {
        $this->assertSame(5, DB::table('menu_groups')->count());
        $this->assertSame(['customers', 'coupons', 'reports', 'infra', 'system'], DB::table('menu_groups')->orderBy('position')->pluck('key')->all());
        $this->assertSame(['coupon-batches', 'coupon-activations', 'coupons'], DB::table('menu_items')->join('menu_groups', 'menu_groups.id', '=', 'menu_items.menu_group_id')->where('key', 'coupons')->orderBy('menu_items.position')->limit(3)->pluck('ref')->all());
        $this->assertContains('menu-editor', DB::table('menu_items')->pluck('ref')->all());
    }

    public function test_only_the_super_admin_opens_and_saves_the_editor(): void
    {
        $this->actingAs($this->super(), 'admin')->get(route('admin.menu.index'))->assertOk()->assertSee('Menú del panel')->assertSee('Nuevo grupo')->assertSee('Lotes de cupones');

        Permission::findOrCreate('roles.edit', 'admin');
        $other = AdminUser::factory()->create();
        $other->givePermissionTo('roles.edit');
        $this->actingAs($other, 'admin');
        $this->get(route('admin.menu.index'))->assertForbidden();
        $this->save($this->current())->assertForbidden();
        $this->post(route('admin.menu.reset'))->assertForbidden();
    }

    public function test_the_editor_entry_is_in_the_settings_menu_for_the_super_admin_only(): void
    {
        $super = $this->super();
        $labels = collect(AdminNavigation::groups($super))->pluck('items')->flatten(1)->pluck('label');
        $this->assertContains('Menú del panel', $labels);

        $plain = AdminUser::factory()->create();
        $this->assertNotContains('Menú del panel', collect(AdminNavigation::groups($plain))->pluck('items')->flatten(1)->pluck('label'));
    }

    public function test_renaming_reordering_moving_and_hiding_change_the_navbar(): void
    {
        $this->actingAs($this->super(), 'admin');
        $groups = $this->current();

        $groups[1]['label'] = 'Cupones y lotes';
        $groups[1]['icon'] = 'ticket';
        // «Reglas de uso» se mueve al grupo de reportes, con otro nombre e icono
        $moved = collect($groups[1]['items'])->firstWhere('ref', 'topup-rules');
        $groups[1]['items'] = array_values(array_filter($groups[1]['items'], fn ($i) => $i['ref'] !== 'topup-rules'));
        $moved['label'] = 'Condiciones de uso';
        $moved['icon'] = 'scale';
        $groups[2]['items'][] = $moved;
        // «Prefijos» se oculta
        foreach ($groups[1]['items'] as &$i) {
            if ($i['ref'] === 'coupon-prefixes') {
                $i['visible'] = false;
            }
        }
        unset($i);
        // El grupo de infraestructura pasa al principio
        $groups = [$groups[3], $groups[0], $groups[1], $groups[2], $groups[4]];

        $this->save($groups)->assertSessionHasNoErrors()->assertRedirect(route('admin.menu.index'));

        $nav = collect(AdminNavigation::groups($this->super()));
        $this->assertSame('Infraestructura', $nav->first()['label']);
        $coupons = $nav->firstWhere('label', 'Cupones y lotes');
        $this->assertSame('ticket', $coupons['icon']);
        $this->assertNotContains('Reglas de uso', collect($coupons['items'])->pluck('label'));
        $this->assertNotContains('Prefijos de cupones', collect($coupons['items'])->pluck('label'));
        $reports = collect($nav->firstWhere('label', 'Reportes')['items']);
        $this->assertSame('Condiciones de uso', $reports->last()['label']);
        $this->assertSame('scale', $reports->last()['icon']);

        // La página lo refleja
        $html = $this->get(route('admin.dashboard'))->getContent();
        $this->assertStringContainsString('Cupones y lotes', $html);
        $this->assertStringContainsString('Condiciones de uso', $html);
    }

    public function test_custom_links_respect_permissions_and_open_options(): void
    {
        $this->actingAs($this->super(), 'admin');
        $groups = $this->current();
        $groups[0]['items'][] = ['kind' => 'link', 'ref' => null, 'label' => 'Manual', 'icon' => 'academic-cap', 'url' => 'https://ayuda.example.com/manual', 'permission' => null, 'new_tab' => true, 'visible' => true];
        $groups[0]['items'][] = ['kind' => 'link', 'ref' => null, 'label' => 'Solo contadores', 'icon' => 'calculator', 'url' => '/admin/reports/redemptions', 'permission' => 'coupon_reports.view', 'new_tab' => false, 'visible' => true];
        $this->save($groups)->assertSessionHasNoErrors();

        $labels = fn ($admin) => collect(AdminNavigation::groups($admin))->pluck('items')->flatten(1)->pluck('label');
        $this->assertContains('Manual', $labels($this->super()));
        $this->assertContains('Solo contadores', $labels($this->super()));

        $plain = AdminUser::factory()->create();
        Permission::findOrCreate('customers.view', 'admin');
        $plain->givePermissionTo('customers.view');
        $this->assertContains('Manual', $labels($plain));
        $this->assertNotContains('Solo contadores', $labels($plain), 'el enlace con permiso no se muestra sin él');

        $manual = collect(AdminNavigation::groups($plain))->pluck('items')->flatten(1)->firstWhere('label', 'Manual');
        $this->assertTrue($manual['new_tab']);
        $this->actingAs($plain, 'admin');
        $this->get(route('admin.dashboard'))->assertSee('https://ayuda.example.com/manual', false);
    }

    public function test_validation_protects_the_menu(): void
    {
        $this->actingAs($this->super(), 'admin');
        $base = $this->current();

        // Falta una pantalla
        $missing = $base;
        array_pop($missing[1]['items']);
        $this->save($missing)->assertSessionHasErrors('payload');

        // Una pantalla repetida
        $dup = $base;
        $dup[0]['items'][] = $base[1]['items'][0];
        $this->save($dup)->assertSessionHasErrors();

        // Enlaces peligrosos o sin nombre
        foreach (['javascript:alert(1)', '//evil.com', 'http://inseguro.com', 'ftp://x'] as $url) {
            $bad = $base;
            $bad[0]['items'][] = ['kind' => 'link', 'label' => 'X', 'url' => $url];
            $this->save($bad)->assertSessionHasErrors('groups.0.items.1.url');
        }
        $noName = $base;
        $noName[0]['items'][] = ['kind' => 'link', 'label' => '', 'url' => '/admin'];
        $this->save($noName)->assertSessionHasErrors('groups.0.items.1.label');

        // Grupo sin nombre y payload ilegible
        $noGroupName = $base;
        $noGroupName[0]['label'] = '';
        $this->save($noGroupName)->assertSessionHasErrors('groups.0.label');
        $this->put(route('admin.menu.update'), ['payload' => 'no es json'])->assertSessionHasErrors('payload');

        // Nada de lo anterior cambió el menú
        $this->assertSame('Clientes', DB::table('menu_groups')->orderBy('position')->value('label'));
    }

    public function test_the_editor_entry_cannot_be_hidden_and_groups_can_be_added(): void
    {
        $this->actingAs($this->super(), 'admin');
        $groups = $this->current();
        foreach ($groups as &$g) {
            foreach ($g['items'] as &$i) {
                if ($i['ref'] === 'menu-editor') {
                    $i['visible'] = false;
                }
            }
        }
        unset($g, $i);
        $groups[] = ['key' => '', 'label' => 'Ayuda', 'icon' => 'light-bulb', 'visible' => true, 'items' => [['kind' => 'link', 'label' => 'Soporte', 'url' => '/admin', 'icon' => 'envelope']]];
        $this->save($groups)->assertSessionHasNoErrors();

        $labels = collect(AdminNavigation::groups($this->super()));
        $this->assertContains('Menú del panel', $labels->pluck('items')->flatten(1)->pluck('label'), 'el editor sigue visible');
        $this->assertSame('Ayuda', $labels->last()['label']);
        $this->assertSame('ayuda', DB::table('menu_groups')->orderByDesc('position')->value('key'));
    }

    public function test_a_hidden_group_disappears_and_a_group_without_visible_pages_is_not_drawn(): void
    {
        $this->actingAs($this->super(), 'admin');
        $groups = $this->current();
        $groups[0]['visible'] = false;
        $this->save($groups)->assertSessionHasNoErrors();

        $this->assertNotContains('Clientes', collect(AdminNavigation::groups($this->super()))->pluck('label'));
        $this->assertStringNotContainsString('>Clientes<', preg_replace('/\s+/', '', $this->get(route('admin.dashboard'))->getContent()) ?: '');
    }

    public function test_new_screens_in_code_are_appended_to_their_group_and_empty_tables_fall_back_to_the_defaults(): void
    {
        // Una pantalla que el menú guardado aún no conoce se añade a su grupo
        DB::table('menu_items')->where('ref', 'customers')->delete();
        $labels = collect(MenuStructure::load())->pluck('items')->flatten(1)->pluck('ref');
        $this->assertContains('customers', $labels);

        // Sin datos guardados se usa el menú original
        DB::table('menu_items')->delete();
        DB::table('menu_groups')->delete();
        $this->assertSame(5, count(MenuStructure::load()));
        $this->assertContains('Lotes de cupones', collect(AdminNavigation::groups($this->super()))->pluck('items')->flatten(1)->pluck('label'));
    }

    public function test_changes_and_resets_are_audited_and_reset_restores_the_original(): void
    {
        $this->actingAs($this->super(), 'admin');
        $groups = $this->current();
        $groups[0]['label'] = 'Mis clientes';
        $this->save($groups)->assertSessionHasNoErrors();

        $log = AdminAuditLog::where('entity_type', 'Menu')->latest('created_at')->first();
        $this->assertSame('Actualizó el menú del panel', $log->summary);
        $this->assertStringContainsString('Clientes:', $log->changes['estructura'][0]);
        $this->assertStringContainsString('Mis clientes:', $log->changes['estructura'][1]);

        $this->post(route('admin.menu.reset'))->assertRedirect(route('admin.menu.index'));
        $this->assertSame('Clientes', DB::table('menu_groups')->orderBy('position')->value('label'));
        $this->assertTrue(AdminAuditLog::where('summary', 'Restableció el menú del panel al original')->exists());
    }

    public function test_the_editor_shows_every_original_icon(): void
    {
        $html = $this->actingAs($this->super(), 'admin')->get(route('admin.menu.index'))->assertOk()->getContent();
        foreach (collect(MenuStructure::defaults())->pluck('items')->flatten(1)->pluck('ref') as $ref) {
            $this->assertStringContainsString($ref, $html);
        }
        // Los iconos originales de las pantallas (aunque no estén en la lista general) llegan al selector
        $this->assertStringContainsString('squares-plus', $html);
    }
}
