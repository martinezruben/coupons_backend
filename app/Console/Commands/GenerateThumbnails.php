<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\Product;
use App\Services\ImageThumbnailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class GenerateThumbnails extends Command
{
    protected $signature = 'pos:generate-thumbnails
        {--compress-originals : Re-encodea también los originales grandes (máx 1600px)}';

    protected $description = 'Genera miniaturas WebP para imágenes locales de familias y productos (backfill incluido)';

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;
        $compressed = 0;

        foreach ([Family::class, Product::class] as $model) {
            $model::query()
                ->whereNotNull('image_url')
                ->where('image_url', 'like', '%/storage/%')
                ->each(function ($row) use (&$created, &$skipped, &$compressed): void {
                    preg_match('#/storage/(.+)$#', $row->image_url, $m);
                    $thumbPath = dirname($m[1]).'/thumbs/'.pathinfo($m[1], PATHINFO_FILENAME).'.webp';
                    if (Storage::disk('public')->exists($thumbPath)) {
                        $skipped++;
                    } else {
                        $created += ImageThumbnailService::generate($m[1]) !== null ? 1 : 0;
                    }

                    if ($this->option('compress-originals') && Storage::disk('public')->exists($m[1])) {
                        $compressed += ImageThumbnailService::compressOriginal($m[1]) ? 1 : 0;
                    }
                });
        }

        $this->info("Miniaturas generadas: {$created}. Ya existentes: {$skipped}."
            .($this->option('compress-originals') ? " Originales comprimidos: {$compressed}." : ''));

        return self::SUCCESS;
    }
}
