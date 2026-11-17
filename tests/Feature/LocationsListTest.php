<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LocationsListTest extends TestCase
{
    use RefreshDatabase;

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

        $first = $this->get(route('admin.screens.index', 'locations'))->assertOk()->assertSee('Sede 01')->assertSee('Sede 10')->assertDontSee('Sede 11')->assertSee('25 localidades')->getContent();
        $this->assertStringContainsString('Mostrando 1–10 de 25', $first);
        $this->get(route('admin.screens.index', ['locations', 'page' => 3]))->assertOk()->assertSee('Sede 21')->assertSee('Sede 25')->assertDontSee('Sede 20')->assertSee('Mostrando 21–25 de 25');
    }

    public function test_search_runs_on_the_whole_list_from_three_letters(): void
    {
        foreach (range(1, 25) as $i) {
            Location::factory()->create(['name' => sprintf('Sede %02d', $i), 'is_active' => true]);
        }
        Location::factory()->create(['name' => 'Farmacia Norte', 'address' => 'Av. Libertad 45', 'is_active' => true]);
        $this->actingAs($this->viewer(), 'admin');

        // Encuentra una localidad que está en otra página
        $this->get(route('admin.screens.index', ['locations', 'q' => 'far']))->assertOk()->assertSee('Farmacia Norte')->assertDontSee('Sede 01')->assertSee('1 localidad');
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
        $this->get(route('admin.screens.index', ['locations', 'filter' => ['is_active' => '0']]))->assertOk()->assertSee('Cerrada Dos')->assertDontSee('Activa Uno');
    }
}
