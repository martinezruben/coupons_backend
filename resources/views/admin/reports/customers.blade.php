<x-admin.layouts.app title="Reporte de clientes">
    <div class="snow-card">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">Por cliente: cupones asignados, activos e inactivos, lo programado, el saldo de los activos y lo cobrado neto.</p>
        </div>
        <form method="GET" action="{{ route('admin.reports.customers') }}" class="grid grid-cols-2 items-end gap-2 border-b border-slate-200 p-3 sm:grid-cols-4">
            <div class="col-span-2"><label class="snow-label" for="q">Buscar cliente</label><input id="q" name="q" type="search" value="{{ $q }}" class="snow-input" placeholder="Nombre o documento"></div>
            <div>
                <label class="snow-label" for="active">Estado</label>
                <select id="active" name="active" class="snow-input">
                    <option value="">Todos</option>
                    <option value="1" @selected($active === '1')>Activos</option>
                    <option value="0" @selected($active === '0')>Inactivos</option>
                </select>
            </div>
            <div class="flex gap-2"><button type="submit" class="snow-btn-primary">Ver</button><a href="{{ $exportUrl }}" class="snow-btn-secondary">CSV</a></div>
            @if($errors->any())<p class="col-span-full text-xs text-red-600">{{ $errors->first() }}</p>@endif
        </form>

        <div class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-5">
            @foreach([['Asignados', number_format($totals['assigned'])], ['Activos', number_format($totals['active'])], ['Programados', number_format($totals['scheduled'])], ['Saldo en circulación', \App\Support\Format::money($totals['circulating'])], ['Cobrado neto', \App\Support\Format::money($totals['redeemed'])]] as [$label, $value])
                <div class="rounded-md border border-slate-200 p-3"><div class="subheader">{{ $label }}</div><div class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</div></div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[900px]">
                <thead><tr>
                    <th class="text-left">Cliente</th><th class="text-right">Asignados</th><th class="text-right">Activos</th><th class="text-right">Inactivos</th>
                    <th class="text-right">Programados</th><th class="text-right">Saldo circ.</th><th class="text-right">Cobrado neto</th><th class="text-left">Última activación</th>
                </tr></thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td>
                                <a href="{{ route('admin.coupons.index') }}?customer={{ $r->customer->id }}" class="font-medium text-primary-600 hover:underline">{{ $r->customer->name }}</a>
                                <span class="block text-xs text-slate-500">{{ $r->customer->document ?? 'sin documento' }}@unless($r->customer->is_active) · inactivo @endunless</span>
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($r->assigned) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($r->active) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($r->inactive) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($r->scheduled) }}</td>
                            <td class="text-right tabular-nums">{{ \App\Support\Format::money($r->circulating) }}</td>
                            <td class="text-right font-semibold tabular-nums">{{ \App\Support\Format::money($r->redeemed) }}</td>
                            <td>{{ $r->last?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-10 text-center text-slate-500">No hay clientes con ese criterio.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layouts.app>
