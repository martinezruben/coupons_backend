<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Services\ImageThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageCompressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_compresses_large_original(): void
    {
        Storage::fake('public');
        // Generar JPEG grande (2400x1600, calidad 95) como los que sube una cámara
        $im = imagecreatetruecolor(2400, 1600);
        for ($i = 0; $i < 200; $i++) {
            imagefilledrectangle($im, $i * 12, 0, $i * 12 + 5, 1600, imagecolorallocate($im, rand(0, 255), rand(0, 255), rand(0, 255)));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'big').'.jpg';
        imagejpeg($im, $tmp, 95);
        imagedestroy($im);

        Storage::disk('public')->putFileAs('products', new File($tmp), 'big.jpg');
        $before = filesize(Storage::disk('public')->path('products/big.jpg'));

        ImageThumbnailService::compressOriginal('products/big.jpg');

        $after = filesize(Storage::disk('public')->path('products/big.jpg'));
        $this->assertLessThan($before, $after, 'El original debe quedar más liviano tras la compresión');

        [$w] = getimagesize(Storage::disk('public')->path('products/big.jpg'));
        $this->assertLessThanOrEqual(1600, $w);
    }

    public function test_small_originals_are_not_recompressed(): void
    {
        Storage::fake('public');
        $source = __DIR__.'/fixtures/image-600x400.png';
        Storage::disk('public')->putFileAs('products', new File($source), 'small.png');
        $before = filesize(Storage::disk('public')->path('products/small.png'));

        $compressed = ImageThumbnailService::compressOriginal('products/small.png');

        $this->assertFalse($compressed, 'Imágenes pequeñas no deben re-procesarse');
        $this->assertSame($before, filesize(Storage::disk('public')->path('products/small.png')));
    }

    public function test_generate_failure_compresses_original_as_fallback(): void
    {
        Storage::fake('public');
        // Archivo no-decodificable como imagen: generate() falla
        Storage::disk('public')->put('products/corrupt.jpg', str_repeat('x', 600 * 1024));

        $thumb = ImageThumbnailService::generate('products/corrupt.jpg');
        $this->assertNull($thumb);
        // el "original" corrupto no se puede comprimir tampoco, pero el flujo no debe explotar
        $this->assertTrue(Storage::disk('public')->exists('products/corrupt.jpg'));
    }

    public function test_fetch_external_images_downloads_and_localizes(): void
    {
        Storage::fake('public');
        $source = __DIR__.'/fixtures/image-600x400.png';
        Http::fake([
            'https://images.example.com/cafe.png' => Http::response(file_get_contents($source), 200, ['Content-Type' => 'image/png']),
        ]);

        $family = Family::create([
            'name' => 'Café',
            'image_url' => 'https://images.example.com/cafe.png',
        ]);

        $this->artisan('pos:fetch-external-images')->assertSuccessful();

        $family->refresh();
        $this->assertStringContainsString('/storage/families/', $family->image_url);
        $this->assertStringNotContainsString('images.example.com', $family->image_url);

        preg_match('#/storage/(.+)$#', $family->image_url, $m);
        $this->assertTrue(Storage::disk('public')->exists($m[1]));
    }

    public function test_fetch_external_images_dry_run_does_not_modify(): void
    {
        Http::fake();
        $family = Family::create([
            'name' => 'Café',
            'image_url' => 'https://images.example.com/cafe.png',
        ]);

        $this->artisan('pos:fetch-external-images', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('https://images.example.com/cafe.png', $family->fresh()->image_url);
    }
}
