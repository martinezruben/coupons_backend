<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminCustomersTest extends TestCase
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

    public function test_customers_crud_validates_and_keeps_documents_unique(): void
    {
        $this->actingAs($this->admin('customers.view', 'customers.edit'), 'admin');
        $store = fn (array $d) => $this->post(route('admin.screens.store', 'customers'), $d);

        $store(['name' => '', 'is_active' => 1])->assertSessionHasErrors('name');
        $store(['name' => 'Ana', 'email' => 'no-es-correo', 'is_active' => 1])->assertSessionHasErrors('email');
        $store(['name' => 'Empresa Uno', 'document' => '131-00000-1', 'email' => 'uno@example.com', 'phone' => '809-555-0100', 'is_active' => 1])->assertSessionHasNoErrors();
        $store(['name' => 'Otra', 'document' => '131-00000-1', 'is_active' => 1])->assertSessionHasErrors('document');

        $customer = Customer::where('document', '131-00000-1')->first();
        $this->assertTrue($customer->is_active);
        $this->put(route('admin.screens.update', ['customers', $customer->id]), ['name' => 'Empresa Uno SRL', 'document' => '131-00000-1', 'is_active' => 0])->assertSessionHasNoErrors();
        $customer->refresh();
        $this->assertSame('Empresa Uno SRL', $customer->name);
        $this->assertFalse($customer->is_active);
    }

    public function test_customers_cannot_be_deleted_only_deactivated(): void
    {
        $customer = Customer::create(['name' => 'Con historial']);

        $this->actingAs($this->admin('customers.view', 'customers.edit', 'customers.delete'), 'admin')
            ->delete(route('admin.screens.destroy', ['customers', $customer->id]))->assertNotFound();
        $this->assertNotNull($customer->fresh());
    }

    public function test_customers_screen_requires_permission(): void
    {
        $this->actingAs($this->admin('coupons.view'), 'admin')->get(route('admin.screens.index', 'customers'))->assertForbidden();
        $this->actingAs($this->admin('customers.view'), 'admin')->get(route('admin.screens.index', 'customers'))->assertOk();
        $this->actingAs($this->admin('customers.view'), 'admin')->post(route('admin.screens.store', 'customers'), ['name' => 'X'])->assertForbidden();
    }
}
