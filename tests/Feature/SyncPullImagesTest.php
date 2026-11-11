<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Family;
use App\Models\License;
use App\Models\Location;
use App\Models\Product;
use App\Models\Subfamily;
use App\Services\ImageThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncPullImagesTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $location = Location::create(['id' => (string) Str::uuid(), 'name' => 'Main', 'is_active' => true]);
        $this->device = Device::create([
            'id' => (string) Str::uuid(),
            'location_id' => $location->id,
            'device_fingerprint' => 'fp-img-1',
            'is_enabled' => true,
        ]);
        License::create([
            'device_id' => $this->device->id,
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addDay(),
            'status' => 'ACTIVE',
        ]);
        $this->token = $this->device->createToken('img-test')->plainTextToken;
    }

    private function pull(): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/sync/pull?'.http_build_query(['device_fingerprint' => $this->device->device_fingerprint]));
    }

    public function test_local_image_returns_absolute_thumbnail_url(): void
    {
        Storage::fake('public');

        $source = __DIR__.'/fixtures/image-600x400.png';
        Storage::disk('public')->putFileAs('families', new File($source), 'img.png');
        Storage::disk('public')->putFileAs('products', new File($source), 'prod.png');

        ImageThumbnailService::generate('families/img.png');
        ImageThumbnailService::generate('products/prod.png');

        $family = Family::create(['name' => 'Bebidas', 'image_url' => '/storage/families/img.png']);
        $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Gaseosas']);
        Product::create([
            'sku' => 'SKU-IMG',
            'name' => 'Cola',
            'subfamily_id' => $sub->id,
            'price' => 1.5,
            'tax_rate' => 12,
            'is_active' => true,
            'image_url' => '/storage/products/prod.png',
        ]);

        $res = $this->pull();
        $res->assertOk();

        $familyRow = collect($res->json('data.families'))->firstWhere('id', $family->id);
        $productRow = collect($res->json('data.products'))->firstWhere('sku', 'SKU-IMG');

        $this->assertNotNull($familyRow['imageUrl']);
        $this->assertTrue(str_ends_with($familyRow['imageUrl'], '/storage/families/thumbs/img.webp'));
        $this->assertTrue(str_ends_with($productRow['imageUrl'], '/storage/products/thumbs/prod.webp'));

        $this->assertFileExists(Storage::disk('public')->path('families/thumbs/img.webp'));
    }

    public function test_webp_upload_generates_webp_thumbnail(): void
    {
        Storage::fake('public');

        $source = __DIR__.'/fixtures/image-600x400.webp';
        Storage::disk('public')->putFileAs('products', new File($source), 'prod.webp');

        $thumb = ImageThumbnailService::generate('products/prod.webp');
        $this->assertNotNull($thumb, 'GD debe poder decodificar WebP y generar la miniatura');
        $this->assertSame('products/thumbs/prod.webp', $thumb);
        $this->assertFileExists(Storage::disk('public')->path($thumb));

        // syncUrl apunta a la miniatura cuando existe
        $url = ImageThumbnailService::syncUrl(Storage::disk('public')->url('products/prod.webp'));
        $this->assertStringEndsWith('/storage/products/thumbs/prod.webp', $url);
    }

    public function test_legacy_absolute_url_is_rewritten_to_current_app_url(): void
    {
        Storage::fake('public');
        // imagen almacenada con un APP_URL anterior (host que ya no aplica)
        $legacy = 'https://posbackend.test/storage/families/vieja.png';

        $sync = ImageThumbnailService::syncUrl($legacy);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/storage/', $sync);
        $this->assertStringEndsWith('/storage/families/vieja.png', $sync);

        $display = ImageThumbnailService::displayUrl($legacy);
        $this->assertSame('/storage/families/vieja.png', $display);
    }

    public function test_display_url_is_relative_for_admin_grid(): void
    {
        Storage::fake('public');
        $url = ImageThumbnailService::displayUrl('/storage/families/abc.png');
        $this->assertSame('/storage/families/abc.png', $url);

        // URL verdaderamente externa pasa intacta
        $ext = ImageThumbnailService::displayUrl('https://cdn.example.com/x.png');
        $this->assertSame('https://cdn.example.com/x.png', $ext);
    }

    public function test_external_image_url_passes_through_unchanged(): void
    {
        $family = Family::create(['name' => 'Bebidas', 'image_url' => 'https://example.com/cafe.jpg']);
        $sub = Subfamily::create(['family_id' => $family->id, 'name' => 'Gaseosas']);
        Product::create([
            'sku' => 'SKU-EXT',
            'name' => 'Café',
            'subfamily_id' => $sub->id,
            'price' => 2,
            'tax_rate' => 12,
            'is_active' => true,
            'image_url' => 'https://images.example.com/cafe.jpg',
        ]);

        $res = $this->pull();
        $res->assertOk();

        $familyRow = collect($res->json('data.families'))->firstWhere('id', $family->id);
        $productRow = collect($res->json('data.products'))->firstWhere('sku', 'SKU-EXT');

        $this->assertSame('https://example.com/cafe.jpg', $familyRow['imageUrl']);
        $this->assertSame('https://images.example.com/cafe.jpg', $productRow['imageUrl']);
    }

    public function test_local_image_without_thumbnail_falls_back_to_original(): void
    {
        Storage::fake('public');

        $family = Family::create(['name' => 'Snacks', 'image_url' => '/storage/families/no-thumb.png']);

        $res = $this->pull();
        $familyRow = collect($res->json('data.families'))->firstWhere('id', $family->id);

        $this->assertTrue(str_ends_with($familyRow['imageUrl'], '/storage/families/no-thumb.png'));
    }
}
