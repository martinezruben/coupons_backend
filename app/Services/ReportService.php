<?php

namespace App\Services;

use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function getProductsBestSellers(?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $locationId = null)
    {
        $dateFrom = $dateFrom ?? now()->subMonth();
        $dateTo = $dateTo ?? now();

        $query = Product::select(
            'products.id',
            'products.name',
            'products.sku',
            'products.tax_rate',
            DB::raw('COALESCE(SUM(transaction_items.qty), 0) as sold_qty'),
            DB::raw('COALESCE(SUM(transaction_items.line_total), 0) as total_revenue'),
            DB::raw('CASE WHEN COALESCE(SUM(transaction_items.qty), 0) > 0 THEN COALESCE(SUM(transaction_items.line_total), 0) / SUM(transaction_items.qty) ELSE 0 END as avg_price')
        )
            ->leftJoin('transaction_items', 'products.id', '=', 'transaction_items.product_id')
            ->leftJoin('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.status', 'PAID')
            ->whereBetween('transactions.occurred_at', [$dateFrom, $dateTo])
            ->when($locationId, fn ($q) => $q->where('transactions.location_id', $locationId))
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.tax_rate')
            ->orderBy('sold_qty', 'DESC')
            ->get();

        return $query;
    }

    /**
     * Productos activos sin ventas cobradas (PAID) en el periodo; con la fecha de su última
     * venta (en cualquier fecha, dentro de la localidad si se filtra). Los nunca vendidos
     * salen primero. Un producto creado después del periodo no se considera.
     *
     * @return Collection<int, Product>
     */
    public function getProductsWithoutMovement(?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $locationId = null)
    {
        $dateFrom = $dateFrom ?? now()->subMonth()->startOfDay();
        $dateTo = $dateTo ?? now()->endOfDay();

        $paidSales = fn ($q) => $q->from('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->whereColumn('ti.product_id', 'products.id')
            ->where('t.status', 'PAID')
            ->when($locationId, fn ($q2) => $q2->where('t.location_id', $locationId));

        return Product::query()
            ->where('products.is_active', true)
            ->where('products.created_at', '<=', $dateTo)
            ->whereNotExists(fn ($q) => $paidSales($q)->select(DB::raw(1))->whereBetween('t.occurred_at', [$dateFrom, $dateTo]))
            ->select('products.*')
            ->selectSub(fn ($q) => $paidSales($q)->selectRaw('MAX(t.occurred_at)'), 'last_sold_at')
            ->with('subfamily.family')
            ->orderByRaw('last_sold_at IS NOT NULL')
            ->orderBy('last_sold_at')
            ->orderBy('products.name')
            ->limit(2000)
            ->get();
    }

    /** Ingreso neto de una línea para el margen (ver `config('pos.line_total_includes_tax')`). */
    private function netRevenueSql(): string
    {
        return config('pos.line_total_includes_tax', true) ? '(ti.line_total - ti.tax)' : 'ti.line_total';
    }

    /**
     * Margen por producto en el periodo, solo con las líneas que tienen costo congelado
     * (`unit_cost`). Los productos vendidos sin costo salen al final con margen nulo.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function getProductsMargin(?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $locationId = null)
    {
        $dateFrom = $dateFrom ?? now()->subMonth()->startOfDay();
        $dateTo = $dateTo ?? now()->endOfDay();
        $net = $this->netRevenueSql();

        return DB::table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->join('products as p', 'p.id', '=', 'ti.product_id')
            ->where('t.status', 'PAID')
            ->whereBetween('t.occurred_at', [$dateFrom, $dateTo])
            ->when($locationId, fn ($q) => $q->where('t.location_id', $locationId))
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->selectRaw("p.id, p.name, p.sku, SUM(ti.qty) as qty, SUM(CASE WHEN ti.unit_cost IS NOT NULL THEN ti.qty ELSE 0 END) as costed_qty, SUM(CASE WHEN ti.unit_cost IS NOT NULL THEN {$net} ELSE 0 END) as revenue, SUM(CASE WHEN ti.unit_cost IS NOT NULL THEN ti.qty * ti.unit_cost ELSE 0 END) as cost")
            ->get()
            ->map(function ($row): array {
                $costed = (float) $row->costed_qty > 0;
                $revenue = round((float) $row->revenue, 2);
                $cost = round((float) $row->cost, 2);

                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'sku' => $row->sku,
                    'qty' => (float) $row->qty,
                    'costed_qty' => (float) $row->costed_qty,
                    'revenue' => $costed ? $revenue : null,
                    'cost' => $costed ? $cost : null,
                    'margin' => $costed ? round($revenue - $cost, 2) : null,
                    'margin_pct' => $costed && $revenue > 0 ? round(100 * ($revenue - $cost) / $revenue, 1) : null,
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($a['margin'] === null) <=> ($b['margin'] === null),
                fn ($a, $b) => ($b['margin'] ?? 0) <=> ($a['margin'] ?? 0),
            ])
            ->values();
    }

    /**
     * Resumen del margen del periodo y qué parte de las ventas tiene costo (cobertura),
     * para no presentar como total un margen calculado sobre una fracción.
     *
     * @return array{revenue: float, cost: float, margin: ?float, margin_pct: ?float, coverage_pct: ?float}
     */
    public function getMarginSummary(Carbon $dateFrom, Carbon $dateTo, ?string $locationId = null): array
    {
        $net = $this->netRevenueSql();

        $row = DB::table('transaction_items as ti')
            ->join('transactions as t', 't.id', '=', 'ti.transaction_id')
            ->where('t.status', 'PAID')
            ->whereBetween('t.occurred_at', [$dateFrom, $dateTo])
            ->when($locationId, fn ($q) => $q->where('t.location_id', $locationId))
            ->selectRaw("COALESCE(SUM(CASE WHEN ti.unit_cost IS NOT NULL THEN {$net} ELSE 0 END), 0) as revenue, COALESCE(SUM(CASE WHEN ti.unit_cost IS NOT NULL THEN ti.qty * ti.unit_cost ELSE 0 END), 0) as cost, COALESCE(SUM(CASE WHEN ti.unit_cost IS NOT NULL THEN ti.line_total ELSE 0 END), 0) as costed_gross, COALESCE(SUM(ti.line_total), 0) as all_gross, COUNT(CASE WHEN ti.unit_cost IS NOT NULL THEN 1 END) as costed_lines")
            ->first();

        $revenue = round((float) $row->revenue, 2);
        $cost = round((float) $row->cost, 2);
        $hasCost = (int) $row->costed_lines > 0;

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'margin' => $hasCost ? round($revenue - $cost, 2) : null,
            'margin_pct' => $hasCost && $revenue > 0 ? round(100 * ($revenue - $cost) / $revenue, 1) : null,
            'coverage_pct' => (float) $row->all_gross > 0 ? round(100 * (float) $row->costed_gross / (float) $row->all_gross, 1) : null,
        ];
    }

    public function getPaymentMethodsReport(?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $locationId = null)
    {
        $dateFrom = $dateFrom ?? now()->subMonth();
        $dateTo = $dateTo ?? now();

        // Por fecha de la venta (no de sincronización del pago) y solo ventas cobradas
        $totals = DB::table('transaction_payments')
            ->join('transactions', 'transaction_payments.transaction_id', '=', 'transactions.id')
            ->where('transactions.status', 'PAID')
            ->whereBetween('transactions.occurred_at', [$dateFrom, $dateTo])
            ->when($locationId, fn ($q) => $q->where('transactions.location_id', $locationId))
            ->select(
                'transaction_payments.payment_method as name',
                DB::raw('COUNT(DISTINCT transaction_payments.transaction_id) as total_transactions'),
                DB::raw('COALESCE(SUM(transaction_payments.amount), 0) as total_amount')
            )
            ->groupBy('transaction_payments.payment_method')
            ->get();

        $grandTotal = $totals->sum('total_amount') ?: 1;
        $labels = PaymentMethod::labelsFor($totals->pluck('name'));

        $totals = $totals->map(fn ($item) => [
            'name' => $labels[$item->name] ?? $item->name,
            'total_transactions' => $item->total_transactions,
            'total_amount' => $item->total_amount,
            'percentage' => ($item->total_amount / $grandTotal) * 100,
        ]);

        return $totals->sortByDesc('total_amount')->values();
    }

    public function getUsersPerformanceReport(?Carbon $dateFrom = null, ?Carbon $dateTo = null, ?string $locationId = null)
    {
        $dateFrom = $dateFrom ?? now()->subMonth();
        $dateTo = $dateTo ?? now();

        $users = User::select(
            'users.id',
            'users.username',
            'users.full_name',
            DB::raw('COUNT(DISTINCT transactions.id) as transaction_count'),
            DB::raw('COALESCE(SUM(transactions.total), 0) as total_sales'),
            DB::raw('CASE WHEN COUNT(DISTINCT transactions.id) > 0 THEN COALESCE(SUM(transactions.total), 0) / COUNT(DISTINCT transactions.id) ELSE 0 END as avg_transaction'),
            DB::raw('MAX(transactions.occurred_at) as last_activity')
        )
            ->leftJoin('transactions', 'users.id', '=', 'transactions.user_id')
            ->where('transactions.status', 'PAID')
            ->whereBetween('transactions.occurred_at', [$dateFrom, $dateTo])
            ->when($locationId, fn ($q) => $q->where('transactions.location_id', $locationId))
            ->where('users.is_active', true)
            ->groupBy('users.id', 'users.username', 'users.full_name')
            ->orderBy('total_sales', 'DESC')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
                'transaction_count' => $user->transaction_count,
                'total_sales' => $user->total_sales,
                'avg_transaction' => $user->avg_transaction,
                'last_activity' => is_string($user->last_activity) ? (strtotime($user->last_activity) ? now()->parse($user->last_activity)->diffForHumans() : 'N/A') : ($user->last_activity ? $user->last_activity->diffForHumans() : 'N/A'),
            ]);

        return $users;
    }
}
