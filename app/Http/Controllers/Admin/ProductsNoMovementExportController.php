<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;

/**
 * Exporta el reporte «Productos sin movimiento» a Excel y CSV.
 */
class ProductsNoMovementExportController extends TabularReportExportController
{
    protected function screen(): string
    {
        return 'products-no-movement';
    }

    protected function title(): string
    {
        return 'Productos sin movimiento';
    }

    protected function filePrefix(): string
    {
        return 'productos-sin-movimiento';
    }

    protected function headers(): array
    {
        return ['Producto', 'SKU', 'Familia', 'Subfamilia', 'Precio', 'Última venta', 'Días sin vender'];
    }

    protected function rows(Carbon $from, Carbon $to, ?string $locationId): array
    {
        return $this->reportService->getProductsWithoutMovement($from, $to, $locationId)
            ->map(function ($product): array {
                $last = $product->last_sold_at ? Carbon::parse($product->last_sold_at) : null;

                return [
                    $product->name,
                    $product->sku,
                    $product->subfamily?->family?->name,
                    $product->subfamily?->name,
                    $product->price,
                    $last?->format('Y-m-d H:i'),
                    $last ? (int) $last->diffInDays(now()) : 'Nunca vendido',
                ];
            })
            ->all();
    }
}
