<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocationsListTest extends TestCase
{
    use RefreshDatabase;

    /** Solo el contenido de la página: la barra superior lista todas las localidades en su selector. */
    private function main(TestResponse $res): string
    {
        $html = $res->assertOk()->getContent();

        return substr($html, (int) strpos($html, '<main'), (int) strpos($html, '</main>') - (int) strpos($html, '<main'));
    }

    private function viewer(): AdminUser
    {
        Permission::findOrCreate('locations.view', 'admin');
        $a = AdminUser::factory()->create();
        $a->givePermissionTo('locations.view');

        return $a;
    }

    public function test_locations_are_paginated_ten_per_page_in_name_order(): void
    {
        foreach (range(1, 25) as $i) {
            Location::factory()->create(['name' => sprintf('Sede %02d', $i), 'is_active' => true]);
        }
        $this->actingAs($this->viewer(), 'admin');

        $first = $this->main($this->get(route('admin.screens.index', 'locations')));
        foreach (['Sede 01', 'Sede 10', '25 localidades', 'Mostrando 1–10 de 25'] as $t) {
            $this->assertStringContainsString($t, $first);
        }
        $this->assertStringNotContainsString('Sede 11', $first);
        $third = $this->main($this->get(route('admin.screens.index', ['locations', 'page' => 3])));
        foreach (['Sede 21', 'Sede 25', 'Mostrando 21–25 de 25'] as $t) {
            $this->assertStringContainsString($t, $third);
        }
        $this->assertStringNotContainsString('Sede 20', $third);
    }

    public function test_search_runs_on_the_whole_list_from_three_letters(): void
    {
        foreach (range(1, 25) as $i) {
            Location::factory()->create(['name' => sprintf('Sede %02d', $i), 'is_active' => true]);
        }
        Location::factory()->create(['name' => 'Farmacia Norte', 'address' => 'Av. Libertad 45', 'is_active' => true]);
        $this->actingAs($this->viewer(), 'admin');

        // Encuentra una localidad que está en otra página
        $far = $this->main($this->get(route('admin.screens.index', ['locations', 'q' => 'far'])));
        $this->assertStringContainsString('Farmacia Norte', $far);
        $this->assertStringContainsString('1 localidad', $far);
        $this->assertStringNotContainsString('Sede 01', $far);
        // También por dirección
        $this->get(route('admin.screens.index', ['locations', 'q' => 'libertad']))->assertOk()->assertSee('Farmacia Norte');
        // Con menos de 3 letras no filtra y avisa
        $this->get(route('admin.screens.index', ['locations', 'q' => 'fa']))->assertOk()->assertSee('Sede 01')->assertSee('escribe al menos 3 letras');
        // Sin resultados
        $this->get(route('admin.screens.index', ['locations', 'q' => 'zzzz']))->assertOk()->assertSee('No hay localidades con esos filtros');
        // Los contadores por estado cuentan todas, no solo la búsqueda
        $this->get(route('admin.screens.index', ['locations', 'q' => 'far']))->assertSee('Sin sincronizar');
    }

    public function test_the_search_box_triggers_after_three_letters_and_filters_by_active(): void
    {
        Location::factory()->create(['name' => 'Activa Uno', 'is_active' => true]);
        Location::factory()->create(['name' => 'Cerrada Dos', 'is_active' => false]);
        $this->actingAs($this->viewer(), 'admin');

        $html = $this->get(route('admin.screens.index', 'locations'))->assertOk()->getContent();
        $this->assertStringContainsString('$el.value.length >= 3', $html);
        $inactive = $this->main($this->get(route('admin.screens.index', ['locations', 'filter' => ['is_active' => '0']])));
        $this->assertStringContainsString('Cerrada Dos', $inactive);
        $this->assertStringNotContainsString('Activa Uno', $inactive);
    }
}
