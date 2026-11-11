<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Family;
use App\Models\Product;
use App\Models\Subfamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminProductGridImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'products.view', 'guard_name' => 'admin']);
        Permission::create(['name' => 'products.edit', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'product-viewer', 'guard_name' => 'admin']);
        $role->givePermissionTo(['products.view', 'products.edit']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);
        $this->actingAs($admin, 'admin');
    }

    public function test_products_grid_shows_thumbnail_column_for_imaged_products(): void
    {
        $family = Family::create(['name' => 'Bebidas']);
        $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Gaseosas']);
        Product::create([
            'sku' => 'SKU-IMG',
            'name' => 'Cola',
            'subfamily_id' => $sub->id,
            'price' => 1.5,
            'tax_rate' => 12,
            'is_active' => true,
            'is_favorite' => true,
            'image_url' => 'https://example.com/cola.jpg',
        ]);
        Product::create([
            'sku' => 'SKU-NOIMG',
            'name' => 'Agua',
            'subfamily_id' => $sub->id,
            'price' => 1,
            'tax_rate' => 12,
            'is_active' => true,
        ]);

        $html = $this->get(route('admin.screens.index', 'products'))->getContent();

        // columna de imagen visible y con miniatura para el producto con imagen
        $this->assertStringContainsString('Imagen', $html);
        $this->assertStringContainsString(
            '<img src="https://example.com/cola.jpg"',
            $html,
        );
        // el producto sin imagen muestra el placeholder suave, no un img roto
        $this->assertStringContainsString('SKU-NOIMG', $html);
        $this->assertStringContainsString('Sin imagen', $html);
        $this->assertStringNotContainsString('src=""', $html);
        // columna de imagen en segunda posición, tras el SKU
        $this->assertGreaterThan(
            strpos($html, 'SKU'),
            strpos($html, 'Imagen'),
            'La columna Imagen debe ir después del SKU',
        );
        // favorito activo: badge verde con "sí", sin el "1"
        $this->assertStringContainsString('emerald', $html);
        $this->assertStringContainsString('<span class="truncate">sí</span>', $html);
        $this->assertStringNotContainsString('title="1"', $html);
    }

    public function test_product_edit_form_shows_barcode_label(): void
    {
        $family = Family::create(['name' => 'Bebidas']);
        $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Gaseosas']);
        $ean = Product::create([
            'sku' => 'SKU-EAN',
            'barcode' => '7800001234567',
            'name' => 'Cola',
            'subfamily_id' => $sub->id,
            'price' => 1.5,
            'tax_rate' => 12,
            'is_active' => true,
        ]);
        $code128 = Product::create([
            'sku' => 'SKU-C128',
            'barcode' => 'ABC-1239',
            'name' => 'Jugo',
            'subfamily_id' => $sub->id,
            'price' => 2,
            'tax_rate' => 12,
            'is_active' => true,
        ]);

        $html = $this->get(route('admin.screens.edit', ['products', $ean->id]))->getContent();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Etiqueta · código de barras', $html);
        $this->assertStringContainsString('7800001234567', $html);

        $html = $this->get(route('admin.screens.edit', ['products', $code128->id]))->getContent();
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('ABC-1239', $html);
    }
}
