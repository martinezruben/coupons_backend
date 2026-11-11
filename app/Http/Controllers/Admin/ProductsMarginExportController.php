<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;

/**
 * Exporta el reporte «Margen por producto» a Excel y CSV.
 */
class ProductsMarginExportController extends TabularReportExportController
{
    protected function screen(): string
    {
        return 'products-margin';
    }

    protected function title(): string
    {
        return 'Margen por producto';
    }

    protected function filePrefix(): string
    {
        return 'margen-por-producto';
    }

    protected function headers(): array
    {
        return ['Producto', 'SKU', 'Cantidad vendida', 'Cantidad con costo', 'Ingreso neto', 'Costo', 'Margen', 'Margen %'];
    }

    protected function rows(Carbon $from, Carbon $to, ?string $locationId): array
    {
        return $this->reportService->getProductsMargin($from, $to, $locationId)
            ->map(fn (array $p): array => [
                $p['name'],
                $p['sku'],
                $p['qty'],
                $p['costed_qty'],
                $p['revenue'],
                $p['cost'],
                $p['margin'],
                $p['margin_pct'],
            ])
            ->all();
    }
}
