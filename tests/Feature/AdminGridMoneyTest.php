<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminGridMoneyTest extends TestCase
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

    public function test_money_columns_show_currency_format(): void
    {
        Product::factory()->create(['name' => 'Café grande', 'price' => 1250.5]);
        Transaction::factory()->create(['total' => 3000]);

        $this->actingAs($this->admin('products.view'), 'admin')
            ->get(route('admin.screens.index', 'products'))
            ->assertOk()
            ->assertSee('$1,250.50');

        $this->actingAs($this->admin('transactions.view'), 'admin')
            ->get(route('admin.screens.index', 'transactions'))
            ->assertOk()
            ->assertSee('$3,000.00');
    }
}
