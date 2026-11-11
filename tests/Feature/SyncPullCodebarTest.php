<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\Product;
use App\Models\Subfamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncPullCodebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_pull_returns_parametrized_barcodes(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-codebar-check',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);

        $family = Family::create(['name' => 'Bebidas']);
        $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Gaseosas']);
        $p1 = Product::create(['sku' => 'SKU-C1', 'name' => 'Cola', 'subfamily_id' => $sub->id, 'price' => 1.5, 'tax_rate' => 12, 'is_active' => true, 'barcode' => '7800001234567']);
        $p2 = Product::create(['sku' => 'SKU-C2', 'name' => 'Jugo', 'subfamily_id' => $sub->id, 'price' => 2, 'tax_rate' => 12, 'is_active' => true, 'barcode' => '']);
        $p3 = Product::create(['sku' => 'SKU-C3', 'name' => 'Agua', 'subfamily_id' => $sub->id, 'price' => 1, 'tax_rate' => 12, 'is_active' => true]);

        $token = $device->createToken('codebar-check')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $device->device_fingerprint]));

        $res->assertOk();

        $r1 = collect($res->json('data.products'))->firstWhere('id', $p1->id);
        $r2 = collect($res->json('data.products'))->firstWhere('id', $p2->id);
        $r3 = collect($res->json('data.products'))->firstWhere('id', $p3->id);

        $this->assertSame('7800001234567', $r1['codebar'], 'codebar parametrizado debe llegar intacto');
        $this->assertNull($r2['codebar'], 'barcode vacío debe llegar como null');
        $this->assertNull($r3['codebar'], 'barcode null debe llegar como null');
    }
}
