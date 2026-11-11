<x-admin.layouts.app :title="'Ingresos por método de pago'">
    <div class="space-y-4 pb-6">
        @include('admin.reports.partials.filters', [
            'route' => 'admin.reports.payment-methods',
            'exports' => [
                'excel' => route('admin.reports.payment-methods.export'),
                'csv' => route('admin.reports.payment-methods.export-csv'),
                'pdf' => route('admin.reports.payment-methods.export-pdf'),
            ],
            'fileName' => 'ingresos-por-metodo-de-pago',
        ])

        <div class="snow-card overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-hope-card">
            <div class="border-b border-slate-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900">Ingresos por método de pago</h3>
                <p class="text-[10px] text-slate-500">Ventas cobradas (PAID) del periodo según la fecha de la venta</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-[11px]">
                    <thead class="snow-table-head">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Método de pago</th>
                            <th class="px-4 py-2 text-right font-semibold">Transacciones</th>
                            <th class="px-4 py-2 text-right font-semibold">Monto</th>
                            <th class="min-w-[180px] px-4 py-2 font-semibold">Participación</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse ($methods as $method)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-4 py-2.5 font-medium text-slate-800">{{ $method['name'] }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::number($method['total_transactions']) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::money($method['total_amount']) }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 min-w-[120px] flex-1 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-gradient-to-r from-primary-500 to-sky-400" style="width: {{ $method['percentage'] }}%"></div>
                                        </div>
                                        <span class="w-12 shrink-0 text-right text-[10px] tabular-nums text-slate-500">{{ \App\Support\Format::percent($method['percentage']) }}</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-[11px] text-slate-500">No hay ventas en el periodo seleccionado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($methods->isNotEmpty())
                        <tfoot class="border-t border-slate-200 bg-slate-50/80">
                            <tr>
                                <td class="px-4 py-2.5 font-semibold text-slate-700">Total</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::number($methods->sum('total_transactions')) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::money($methods->sum('total_amount')) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-admin.layouts.app>
