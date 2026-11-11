<x-admin.layouts.app :title="'Productos más vendidos'">
    <div class="space-y-4 pb-6">
        @include('admin.reports.partials.filters', [
            'route' => 'admin.reports.products-best-sellers',
            'exports' => [
                'excel' => route('admin.reports.products-best-sellers.export'),
                'csv' => route('admin.reports.products-best-sellers.export-csv'),
                'pdf' => route('admin.reports.products-best-sellers.export-pdf'),
            ],
            'fileName' => 'productos-mas-vendidos',
        ])

        <div class="snow-card overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-hope-card">
            <div class="border-b border-slate-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900">Productos más vendidos</h3>
                <p class="text-[10px] text-slate-500">Ventas cobradas (PAID) del periodo, ordenadas por cantidad vendida</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-[11px]">
                    <thead class="snow-table-head">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Producto</th>
                            <th class="px-4 py-2 font-semibold">SKU</th>
                            <th class="px-4 py-2 text-right font-semibold">Cantidad</th>
                            <th class="px-4 py-2 text-right font-semibold">Ingresos</th>
                            <th class="px-4 py-2 text-right font-semibold">Precio prom.</th>
                            <th class="px-4 py-2 text-right font-semibold">IVA</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse ($products as $product)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-4 py-2.5 font-medium text-slate-800">{{ $product->name }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-[10px] text-slate-500">{{ $product->sku }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::number($product->sold_qty) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::money($product->total_revenue) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::money($product->avg_price) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">{{ \App\Support\Format::percent($product->tax_rate, 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-[11px] text-slate-500">No hay ventas en el periodo seleccionado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($products->isNotEmpty())
                        <tfoot class="border-t border-slate-200 bg-slate-50/80">
                            <tr>
                                <td class="px-4 py-2.5 font-semibold text-slate-700" colspan="2">Total · {{ $products->count() }} productos</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::number($products->sum('sold_qty')) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::money($products->sum('total_revenue')) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-admin.layouts.app>
