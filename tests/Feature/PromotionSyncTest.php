<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Subfamily;
use App\Models\User;
use App\Support\AdminGridQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PromotionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_includes_promotions_with_scope(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-promo',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $family = Family::create(['name' => 'Bebidas']);
        $global = Promotion::create([
            'name' => 'Black Friday',
            'type' => 'PERCENT',
            'value' => 10,
            'starts_at' => '2026-11-27 00:00:00',
            'ends_at' => '2026-11-30 23:59:59',
            'is_active' => true,
        ]);
        $familyPromo = Promotion::create([
            'name' => 'Bebidas 2x precio oferta',
            'type' => 'PRICE',
            'value' => 0.99,
            'family_id' => $family->id,
            'is_active' => true,
        ]);

        $token = $device->createToken('promo-test')->plainTextToken;
        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk();

        $promos = collect($res->json('data.promotions'));

        $globalRow = $promos->firstWhere('id', $global->id);
        $this->assertNotNull($globalRow);
        $this->assertSame('PERCENT', $globalRow['type']);
        $this->assertSame('GLOBAL', $globalRow['scopeType']);
        $this->assertNull($globalRow['scopeId']);

        $familyRow = $promos->firstWhere('id', $familyPromo->id);
        $this->assertNotNull($familyRow);
        $this->assertSame('FAMILY', $familyRow['scopeType']);
        $this->assertSame($family->id, $familyRow['scopeId']);
        $this->assertSame('Bebidas', $familyRow['scopeName']);
    }

    public function test_pull_promotions_delta_and_tombstones(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-promo-delta',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $promo = Promotion::create(['name' => 'Vigente', 'type' => 'AMOUNT', 'value' => 5, 'is_active' => true]);
        $toRemove = Promotion::create(['name' => 'Por eliminar', 'type' => 'PERCENT', 'value' => 15, 'is_active' => true]);

        $token = $device->createToken('promo-delta')->plainTextToken;

        // primera sync completa: solo no eliminadas
        $res1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk();
        $this->assertCount(2, $res1->json('data.promotions'));
        $ts = $res1->json('syncTimestamp');

        $this->travel(5)->seconds();

        // delta: la edición y el tombstone nuevo (deleted_at > ts)
        $promo->update(['value' => 7]);
        $toRemove->delete();
        $res2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query([
                'device_fingerprint' => $device->device_fingerprint,
                'lastSyncTimestamp' => $ts,
            ]))
            ->assertOk();

        $rows = collect($res2->json('data.promotions'));
        $this->assertContains($promo->id, $rows->pluck('id'));
        $tombstone = $rows->firstWhere('id', $toRemove->id);
        $this->assertNotNull($tombstone, 'El borrado posterior al delta debe llegar como tombstone');
        $this->assertNotNull($tombstone['deletedAt']);
    }

    public function test_admin_can_create_promotion_via_screen(): void
    {
        Permission::create(['name' => 'promotions.view', 'guard_name' => 'admin']);
        Permission::create(['name' => 'promotions.edit', 'guard_name' => 'admin']);
        $role = Role::create(['name' => 'promo-manager', 'guard_name' => 'admin']);
        $role->givePermissionTo(['promotions.view', 'promotions.edit']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'promotions'), [
                'name' => 'Happy Hour',
                'type' => 'PERCENT',
                'value' => '20',
                'starts_at' => '2026-12-01T10:00',
                'ends_at' => '2026-12-15T22:00',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.screens.index', 'promotions'));

        $promo = Promotion::where('name', 'Happy Hour')->first();
        $this->assertNotNull($promo);
        $this->assertSame('PERCENT', $promo->type);
        $this->assertTrue((bool) $promo->is_active);

        User::factory()->create();
    }

    private function promoManager(): AdminUser
    {
        Permission::firstOrCreate(['name' => 'promotions.view', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'promotions.edit', 'guard_name' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'promo-manager-2', 'guard_name' => 'admin']);
        $role->givePermissionTo(['promotions.view', 'promotions.edit']);
        $admin = AdminUser::factory()->create();
        $admin->assignRole($role);

        return $admin;
    }

    public function test_promotion_is_audited(): void
    {
        AdminAuditLog::query()->delete();

        $admin = $this->promoManager();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'promotions'), [
                'name' => 'Auditada',
                'type' => 'PERCENT',
                'value' => '10',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'created',
            'entity_type' => 'Promotion',
            'admin_user_id' => $admin->id,
        ]);
    }

    public function test_bundle_2x1_can_be_created_and_synced(): void
    {
        $admin = $this->promoManager();
        $family = Family::create(['name' => 'Snacks']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.screens.store', 'promotions'), [
                'name' => '2x1 Snacks',
                'type' => 'BUNDLE',
                'value' => '0',
                'buy_qty' => '2',
                'pay_qty' => '1',
                'family_id' => $family->id,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.screens.index', 'promotions'));

        $promo = Promotion::where('name', '2x1 Snacks')->first();
        $this->assertNotNull($promo);
        $this->assertSame('BUNDLE', $promo->type);
        $this->assertEquals(2, $promo->buy_qty);
        $this->assertEquals(1, $promo->pay_qty);

        // payload del sync
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create(['id' => (string) Str::uuid(), 'location_id' => $location->id, 'device_fingerprint' => 'fp-bundle', 'is_enabled' => true]);
        License::create(['device_id' => $device->id, 'valid_from' => now()->subDay(), 'valid_to' => now()->addDay(), 'status' => 'ACTIVE']);
        $token = $device->createToken('bundle')->plainTextToken;

        $row = collect($this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk()
            ->json('data.promotions'))->firstWhere('id', $promo->id);

        $this->assertSame('BUNDLE', $row['type']);
        $this->assertSame(2.0, (float) $row['buyQty']);
        $this->assertSame(1.0, (float) $row['payQty']);
    }

    public function test_promotion_business_rules_are_enforced(): void
    {
        $admin = $this->promoManager();
        $family = Family::create(['name' => 'F A']);
        $subfamily = Subfamily::create(['family_id' => $family->id, 'name' => 'S A']);
        $product = Product::create(['sku' => 'SKU-P1', 'name' => 'Producto 1', 'subfamily_id' => $subfamily->id, 'price' => 10, 'tax_rate' => 12, 'is_active' => true]);
        $this->actingAs($admin, 'admin');

        // PERCENT > 100
        $this->post(route('admin.screens.store', 'promotions'), [
            'name' => 'X1', 'type' => 'PERCENT', 'value' => '150', 'is_active' => '1',
        ])->assertSessionHasErrors('value');

        // PRICE sin scope
        $this->post(route('admin.screens.store', 'promotions'), [
            'name' => 'X2', 'type' => 'PRICE', 'value' => '5', 'is_active' => '1',
        ])->assertSessionHasErrors();

        // BUNDLE sin cantidades
        $this->post(route('admin.screens.store', 'promotions'), [
            'name' => 'X3', 'type' => 'BUNDLE', 'value' => '0', 'family_id' => $family->id, 'is_active' => '1',
        ])->assertSessionHasErrors();

        // dos scopes a la vez
        $this->post(route('admin.screens.store', 'promotions'), [
            'name' => 'X4', 'type' => 'PERCENT', 'value' => '10', 'family_id' => $family->id, 'subfamily_id' => $subfamily->id, 'is_active' => '1',
        ])->assertSessionHasErrors();

        // ends_at anterior a starts_at
        $this->post(route('admin.screens.store', 'promotions'), [
            'name' => 'X5', 'type' => 'PERCENT', 'value' => '10', 'family_id' => $family->id,
            'starts_at' => '2026-06-10T00:00', 'ends_at' => '2026-06-01T00:00', 'is_active' => '1',
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('promotions', 0);
    }

    public function test_cascading_filter_options_carry_parent_metadata(): void
    {
        $family = Family::create(['name' => 'Fam Cascade']);
        $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Sub Cascade']);
        Product::create(['sku' => 'SKU-CAS', 'name' => 'Prod Cascade', 'subfamily_id' => $sub->id, 'price' => 1, 'tax_rate' => 12, 'is_active' => true]);

        $options = AdminGridQuery::filterOptions(config('admin_screens.promotions'));

        // subfamilias llevan parent = family_id
        $subOption = $options['subfamily_id']->firstWhere('id', $sub->id);
        $this->assertSame($family->id, $subOption['parent']);

        // productos llevan parent = subfamily_id
        $productOption = $options['product_id']->first(fn ($o) => $o['label'] === 'Prod Cascade');
        $this->assertSame($sub->id, $productOption['parent']);
    }

    public function test_family_payload_includes_description(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-fam-desc',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $withDesc = Family::create([
            'name' => 'Cervezas, Vinos y Licores',
            'description' => 'Categoría de bebidas alcohólicas: cervezas, vinos y licores.',
        ]);
        Family::create(['name' => 'Sin descripción']);

        $token = $device->createToken('fam-desc')->plainTextToken;
        $rows = collect($this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk()
            ->json('data.families'));

        $row = $rows->firstWhere('id', $withDesc->id);
        $this->assertSame('Categoría de bebidas alcohólicas: cervezas, vinos y licores.', $row['description']);
        $sinDesc = $rows->firstWhere('name', 'Sin descripción');
        $this->assertNull($sinDesc['description']);
    }

    public function test_sync_payload_includes_promotion_description(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-desc',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $family = Family::create(['name' => 'Cigarrillos']);

        // descripción definida por el admin
        $custom = Promotion::create([
            'name' => '2x1 Cigarrillos',
            'description' => '2x1: lleva dos unidades del mismo producto al mismo precio y paga solo una.',
            'type' => 'BUNDLE',
            'value' => 0,
            'buy_qty' => 2,
            'pay_qty' => 1,
            'family_id' => $family->id,
            'is_active' => true,
        ]);

        // sin descripción: se genera una a partir de las reglas
        $auto = Promotion::create([
            'name' => 'Oferta bebidas',
            'type' => 'PRICE',
            'value' => 0.99,
            'family_id' => $family->id,
            'is_active' => true,
        ]);

        $token = $device->createToken('desc')->plainTextToken;
        $rows = collect($this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]))
            ->assertOk()
            ->json('data.promotions'));

        $customRow = $rows->firstWhere('id', $custom->id);
        $this->assertSame(
            '2x1: lleva dos unidades del mismo producto al mismo precio y paga solo una.',
            $customRow['description'],
        );

        $autoRow = $rows->firstWhere('id', $auto->id);
        $this->assertSame('Precio de oferta en Cigarrillos: paga 0.99 por unidad.', $autoRow['description']);
    }

    public function test_bundle_description_auto_mentions_same_product_and_price(): void
    {
        $auto = new Promotion([
            'type' => 'BUNDLE', 'value' => 0, 'buy_qty' => 2, 'pay_qty' => 1,
        ]);

        $desc = $auto->effectiveDescription();
        $this->assertStringContainsString('mismo producto', $desc);
        $this->assertStringContainsString('mismo precio', $desc);
        $this->assertStringContainsString('precio de oferta', $desc);
    }

    public function test_promotions_grid_filters_by_family(): void
    {
        $admin = $this->promoManager();
        $family = Family::create(['name' => 'Fam Filtro']);
        Promotion::create(['name' => 'Con familia', 'type' => 'PERCENT', 'value' => 5, 'family_id' => $family->id, 'is_active' => true]);
        Promotion::create(['name' => 'Global promo', 'type' => 'PERCENT', 'value' => 5, 'is_active' => true]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.screens.index', 'promotions').'?'.http_build_query([
                'filter' => ['family_id' => $family->id],
            ]))
            ->assertOk()
            ->assertSee('Con familia')
            ->assertDontSee('Global promo');
    }
}
