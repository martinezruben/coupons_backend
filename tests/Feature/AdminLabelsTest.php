<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminLabelsTest extends TestCase
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

    public function test_grid_shows_spanish_headers_values_and_local_dates(): void
    {
        Transaction::factory()->create(['status' => 'VOIDED', 'occurred_at' => '2026-09-28 14:05:09']);

        $this->actingAs($this->admin('transactions.view'), 'admin')
            ->get(route('admin.screens.index', 'transactions'))
            ->assertOk()
            ->assertSee('Estado')
            ->assertSee('Sincronizada')
            ->assertSee('ID externo')
            ->assertDontSee('Is Synced')
            ->assertDontSee('External Id')
            ->assertSee('anulada')
            ->assertSee('28/09/2026 14:05');
    }

    public function test_form_labels_and_validation_use_shared_spanish_labels(): void
    {
        $admin = $this->admin('locations.view', 'locations.edit');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.screens.create', 'locations'))
            ->assertOk()
            ->assertSee('Dirección');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'locations'), ['name' => ''])
            ->assertSessionHasErrors(['name' => 'El campo Nombre es obligatorio.']);
    }
}
