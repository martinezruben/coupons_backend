<x-admin.layouts.app title="Reportes de canjes">
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-100 bg-slate-50/80 px-3 py-2 sm:px-4">
            <h1 class="text-sm font-semibold text-slate-900">Reporte de canjes</h1>
            <p class="mt-0.5 text-[10px] text-slate-500">
                Cobrado = cobros de cupones. Neto = cobrado menos reversas. Las recargas se muestran aparte y no cuentan como cobro.
            </p>
        </div>

        <form method="GET" action="{{ route('admin.reports.redemptions') }}" class="grid grid-cols-2 items-end gap-2 border-b border-slate-100 p-3 sm:grid-cols-3 lg:grid-cols-6">
            <div>
                <label class="snow-label" for="from">Desde</label>
                <input id="from" name="from" type="date" value="{{ $from }}" class="snow-input">
            </div>
            <div>
                <label class="snow-label" for="to">Hasta</label>
                <input id="to" name="to" type="date" value="{{ $to }}" class="snow-input">
            </div>
            <div>
                <label class="snow-label" for="group">Agrupar por</label>
                <select id="group" name="group" class="snow-input">
                    @foreach($groups as $key => $label)
                        <option value="{{ $key }}" @selected($group === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="snow-label" for="coupon_type">Tipo de cupón</label>
                <select id="coupon_type" name="coupon_type" class="snow-input">
                    <option value="">Todos</option>
                    <option value="QRCODE" @selected($couponType === 'QRCODE')>QR</option>
                    <option value="CODBAR" @selected($couponType === 'CODBAR')>Código de barras</option>
                </select>
            </div>
            <div>
                <label class="snow-label" for="location_id">Localidad</label>
                <select id="location_id" name="location_id" class="snow-input">
                    <option value="">Todas</option>
                    @foreach($locations as $loc)
                        <option value="{{ $loc->id }}" @selected($locationId === (string) $loc->id)>{{ $loc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="snow-btn-primary">Ver</button>
                <a href="{{ $exportUrl }}" class="snow-btn-secondary">CSV</a>
            </div>
            @if($errors->any())
                <p class="col-span-full text-xs font-medium text-red-600">{{ $errors->first() }}</p>
            @endif
        </form>

        <div class="grid grid-cols-2 gap-3 p-3 sm:grid-cols-5">
            @foreach([
                ['Cobros', number_format($totals['redemptions'])],
                ['Cobrado', \App\Support\Format::money($totals['redeemed'])],
                ['Reversado', \App\Support\Format::money($totals['reversed'])],
                ['Neto cobrado', \App\Support\Format::money($totals['net'])],
                ['Recargado', \App\Support\Format::money($totals['topups'])],
            ] as [$label, $value])
                <div class="rounded-xl border border-slate-200/90 bg-white p-3">
                    <div class="text-[11px] text-slate-500">{{ $label }}</div>
                    <div class="mt-1 text-lg font-semibold text-slate-900">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto p-3 pt-0">
            <table class="w-full min-w-[640px] border-collapse text-left text-[11px]">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-2 py-1.5">{{ $groups[$group] }}</th>
                        <th class="px-2 py-1.5 text-right">Cobros</th>
                        <th class="px-2 py-1.5 text-right">Cobrado</th>
                        <th class="px-2 py-1.5 text-right">Reversado</th>
                        <th class="px-2 py-1.5 text-right">Neto</th>
                        <th class="px-2 py-1.5 text-right">Recargado</th>
                        <th class="w-40 px-2 py-1.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $r)
                        <tr>
                            <td class="px-2 py-1 font-medium text-slate-800">{{ $r->label }}</td>
                            <td class="px-2 py-1 text-right tabular-nums">{{ number_format($r->redemptions) }}</td>
                            <td class="px-2 py-1 text-right tabular-nums">{{ \App\Support\Format::money($r->redeemed) }}</td>
                            <td class="px-2 py-1 text-right tabular-nums text-rose-700">{{ \App\Support\Format::money($r->reversed) }}</td>
                            <td class="px-2 py-1 text-right font-semibold tabular-nums">{{ \App\Support\Format::money($r->net) }}</td>
                            <td class="px-2 py-1 text-right tabular-nums text-sky-700">{{ \App\Support\Format::money($r->topups) }}</td>
                            <td class="px-2 py-1">
                                <div class="h-2 rounded bg-slate-100" title="{{ \App\Support\Format::money($r->net) }}">
                                    <div class="h-2 rounded bg-primary-500" style="width: {{ max(0, min(100, round(abs($r->net) / $maxNet * 100))) }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-2 py-4 text-center text-slate-500">Sin movimientos en este periodo.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layouts.app>
