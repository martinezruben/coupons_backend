<x-admin.layouts.app :title="'Rendimiento de usuarios'">
    <div class="space-y-4 pb-6">
        @include('admin.reports.partials.filters', [
            'route' => 'admin.reports.users-performance',
            'exports' => [
                'excel' => route('admin.reports.users-performance.export'),
                'csv' => route('admin.reports.users-performance.export-csv'),
                'pdf' => route('admin.reports.users-performance.export-pdf'),
            ],
            'fileName' => 'rendimiento-de-usuarios',
        ])

        <div class="snow-card overflow-hidden rounded-xl border border-slate-200/90 bg-white shadow-hope-card">
            <div class="border-b border-slate-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900">Rendimiento de usuarios</h3>
                <p class="text-[10px] text-slate-500">Ventas cobradas (PAID) del periodo por usuario del POS</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-[11px]">
                    <thead class="snow-table-head">
                        <tr>
                            <th class="px-4 py-2 font-semibold">Nombre</th>
                            <th class="px-4 py-2 font-semibold">Usuario</th>
                            <th class="px-4 py-2 text-right font-semibold">Transacciones</th>
                            <th class="px-4 py-2 text-right font-semibold">Ventas</th>
                            <th class="px-4 py-2 text-right font-semibold">Ticket promedio</th>
                            <th class="px-4 py-2 font-semibold">Última venta</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse ($users as $user)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-4 py-2.5 font-medium text-slate-800">{{ $user['full_name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-[10px] text-slate-500">{{ $user['username'] }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::number($user['transaction_count']) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::money($user['total_sales']) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ \App\Support\Format::money($user['avg_transaction']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-[10px] text-slate-500">{{ $user['last_activity'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-[11px] text-slate-500">No hay ventas en el periodo seleccionado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($users->isNotEmpty())
                        <tfoot class="border-t border-slate-200 bg-slate-50/80">
                            <tr>
                                <td class="px-4 py-2.5 font-semibold text-slate-700" colspan="2">Total · {{ $users->count() }} usuarios</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::number($users->sum('transaction_count')) }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">{{ \App\Support\Format::money($users->sum('total_sales')) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-admin.layouts.app>
