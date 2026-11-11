<x-admin.layouts.app :title="'Margen por producto'">
    <div class="space-y-4 pb-6">
        @include('admin.reports.partials.filters', [
            'route' => 'admin.reports.products-margin',
            'exports' => [
                'excel' => route('admin.reports.products-margin.export'),
                'csv' => route('admin.reports.products-margin.export-csv'),
            ],
            'fileName' => 'margen-por-producto',
        ])

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ([
                ['Ingreso neto (con costo)', \App\Support\Format::money($summary['revenue']), null],
                ['Costo', \App\Support\Format::money($summary['cost']), null],
                ['Margen', $summary['margin'] === null ? '—' : \App\Support\Format::money($summary['margin']), $summary['margin_pct'] === null ? null : \App\Support\Format::percent($summary['margin_pct'])],
                ['Cobertura de costos', $summary['coverage_pct'] === null ? '—' : \App\Support\Format::percent($summary['coverage_pct'], 0), 'de las ventas del periodo tiene costo'],
            ] as [$label, $value, $sub])
                <div class="snow-card p-3.5">
                    <p class="font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
                    <p class="mt-1.5 text-xl font-bold tabular-nums text-slate-900">{{ $value }}</p>
                    @if ($sub)
                        <p class="mt-0.5 text-[11px] text-slate-500">{{ $sub }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($summary['coverage_pct'] !== null && $summary['coverage_pct'] < 100)
            <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                El margen se calcula solo con las ventas que tienen costo ({{ \App\Support\Format::percent($summary['coverage_pct'], 0) }} de lo vendido). El costo se congela al sincronizar cada venta: las ventas anteriores a cargar el costo del producto no entran.
            </p>
        @endif

        <div class="snow-card overflow-hidden">
            <div class="border-b border-slate-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900">Margen por producto</h3>
                <p class="text-[11px] text-slate-500">Ventas cobradas (PAID) del periodo con costo, de mayor a menor margen. Ingreso neto sin impuesto.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                    <thead class="snow-table-head">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Producto</th>
                            <th class="px-4 py-2 font-semibold">SKU</th>
                            <th class="px-4 py-2 text-right font-semibold">Cantidad</th>
                            <th class="px-4 py-2 text-right font-semibold">Ingreso neto</th>
                            <th class="px-4 py-2 text-right font-semibold">Costo</th>
                            <th class="px-4 py-2 text-right font-semibold">Margen</th>
                            <th class="px-4 py-2 text-right font-semibold">Margen %</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse ($products as $p)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-4 py-2.5 font-medium text-slate-800">{{ $p['name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-[11px] text-slate-500">{{ $p['sku'] }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::number($p['qty']) }}</td>
                                @if ($p['margin'] === null)
                                    <td colspan="4" class="px-4 py-2.5 text-right"><span class="snow-tag bg-amber-50 text-amber-700">Sin costo cargado</span></td>
                                @else
                                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::money($p['revenue']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::money($p['cost']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums {{ $p['margin'] < 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ \App\Support\Format::money($p['margin']) }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums {{ $p['margin'] < 0 ? 'text-rose-700' : 'text-slate-700' }}">{{ $p['margin_pct'] === null ? '—' : \App\Support\Format::percent($p['margin_pct']) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-6 text-center text-xs text-slate-500">No hay ventas con producto en el periodo seleccionado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin.layouts.app>
