<x-admin.layouts.app :title="'Productos sin movimiento'">
    <div class="space-y-4 pb-6">
        @include('admin.reports.partials.filters', [
            'route' => 'admin.reports.products-no-movement',
            'exports' => [
                'excel' => route('admin.reports.products-no-movement.export'),
                'csv' => route('admin.reports.products-no-movement.export-csv'),
            ],
            'fileName' => 'productos-sin-movimiento',
        ])

        <div class="snow-card overflow-hidden">
            <div class="border-b border-slate-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900">Productos sin movimiento</h3>
                <p class="text-[11px] text-slate-500">Productos activos sin ventas cobradas (PAID) en el periodo. Los que nunca se han vendido aparecen primero.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                    <thead class="snow-table-head">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Producto</th>
                            <th class="px-4 py-2 font-semibold">SKU</th>
                            <th class="px-4 py-2 font-semibold">Familia</th>
                            <th class="px-4 py-2 text-right font-semibold">Precio</th>
                            <th class="px-4 py-2 font-semibold">Última venta</th>
                            <th class="px-4 py-2 text-right font-semibold">Días sin vender</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse ($products as $product)
                            @php($last = $product->last_sold_at ? \Illuminate\Support\Carbon::parse($product->last_sold_at) : null)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-4 py-2.5 font-medium text-slate-800">{{ $product->name }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-[11px] text-slate-500">{{ $product->sku }}</td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    {{ $product->subfamily?->family?->name ?? '—' }}
                                    @if ($product->subfamily)
                                        <span class="text-slate-500">· {{ $product->subfamily->name }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::money($product->price) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">
                                    @if ($last)
                                        {{ $last->format('Y-m-d') }}
                                    @else
                                        <span class="snow-tag bg-amber-50 text-amber-700">Nunca vendido</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $last ? (int) $last->diffInDays(now()) : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-xs text-slate-500">Todos los productos activos tuvieron ventas en el periodo.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($products->isNotEmpty())
                        <tfoot class="border-t border-slate-200 bg-slate-50/80">
                            <tr>
                                <td class="px-4 py-2.5 font-semibold text-slate-700" colspan="6">
                                    {{ \App\Support\Format::number($products->count()) }} productos sin movimiento
                                    @if ($products->count() >= 2000)
                                        <span class="font-normal text-slate-500">(se muestran los primeros 2,000)</span>
                                    @endif
                                </td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-admin.layouts.app>
