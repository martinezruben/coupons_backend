<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\Product;
use App\Services\ImageThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Descarga las image_url externas (CDN, unsplash…) de familias y productos,
 * las almacena localmente comprimidas y actualiza la columna para que el POS
 * siempre descargue del propio servidor ya optimizado.
 */
class FetchExternalImages extends Command
{
    protected $signature = 'pos:fetch-external-images {--dry-run : Solo muestra qué se descargaría}';

    protected $description = 'Descarga y comprime las imágenes externas de familias y productos para servirlas localmente';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $downloaded = 0;
        $failed = 0;

        foreach ([Family::class => 'families', Product::class => 'products'] as $model => $folder) {
            $model::query()
                ->whereNotNull('image_url')
                ->where('image_url', 'like', 'http%')
                ->where('image_url', 'not like', '%/storage/%')
                ->each(function ($row) use ($folder, $dryRun, &$downloaded, &$failed): void {
                    $url = $row->image_url;
                    if ($dryRun) {
                        $this->line("[{$folder}] {$row->name}: {$url}");

                        return;
                    }

                    $newUrl = $this->fetchAndStore($url, $folder);
                    if ($newUrl === null) {
                        $failed++;
                        $this->warn("Fallo: [{$folder}] {$row->name}: {$url}");

                        return;
                    }

                    $row->update(['image_url' => $newUrl]);
                    $downloaded++;
                    $this->info("OK: [{$folder}] {$row->name} → {$newUrl}");
                });
        }

        $dryRun
            ? $this->info('Dry-run: nada fue modificado.')
            : $this->info("Descargadas: {$downloaded}. Fallos: {$failed}.");

        return self::SUCCESS;
    }

    private function fetchAndStore(string $url, string $folder): ?string
    {
        try {
            $response = Http::timeout(20)->retry(2, 500)->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if (strlen($body) < 1024 || strlen($body) > 10 * 1024 * 1024) {
            return null; // demasiado pequeño (no es imagen) o demasiado grande
        }

        $ext = $this->extensionFromResponse($response, $url);
        if ($ext === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = $folder.'/'.Str::random(40).'.'.$ext;
        $disk->put($path, $body);

        ImageThumbnailService::compressOriginal($path);

        if (ImageThumbnailService::generate($path) === null) {
            $this->warn('  miniatura no generada (revisar GD/WebP)');
        }

        return Storage::disk('public')->url($path);
    }

    private function extensionFromResponse($response, string $url): ?string
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        foreach ($map as $mime => $ext) {
            if (str_contains($contentType, $mime)) {
                return $ext;
            }
        }

        // fallback: extensión de la URL
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? ($ext === 'jpeg' ? 'jpg' : $ext) : null;
    }
}
