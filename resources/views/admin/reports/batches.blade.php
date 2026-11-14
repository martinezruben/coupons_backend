<x-admin.layouts.app title="Reporte de lotes">
    <div class="snow-card">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">Cupones de cada lote por estado, saldo de los activos y cobrado neto (cobros menos reversas).</p>
        </div>
        <form method="GET" action="{{ route('admin.reports.batches') }}" class="grid grid-cols-2 items-end gap-2 border-b border-slate-200 p-3 sm:grid-cols-3 lg:grid-cols-6">
            <div><label class="snow-label" for="from">Creados desde</label><input id="from" name="from" type="date" value="{{ $from }}" class="snow-input"></div>
            <div><label class="snow-label" for="to">Hasta</label><input id="to" name="to" type="date" value="{{ $to }}" class="snow-input"></div>
            <div>
                <label class="snow-label" for="status">Estado del lote</label>
                <select id="status" name="status" class="snow-input">
                    <option value="">Todos</option>
                    @foreach(['ready' => 'Listo', 'generating' => 'Generando', 'failed' => 'Fallido', 'void' => 'Anulado'] as $k => $v)
                        <option value="{{ $k }}" @selected($status === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="snow-label" for="coupon_type">Tipo</label>
                <select id="coupon_type" name="coupon_type" class="snow-input">
                    <option value="">Todos</option>
                    <option value="QRCODE" @selected($couponType === 'QRCODE')>QR</option>
                    <option value="CODBAR" @selected($couponType === 'CODBAR')>Código de barras</option>
                </select>
            </div>
            <div class="flex gap-2 lg:col-span-2"><button type="submit" class="snow-btn-primary">Ver</button><a href="{{ $exportUrl }}" class="snow-btn-secondary">CSV</a></div>
            @if($errors->any())<p class="col-span-full text-xs text-red-600">{{ $errors->first() }}</p>@endif
        </form>

        <div class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-6">
            @foreach([['Cupones', number_format($totals['quantity'])], ['Sin asignar', number_format($totals['generated'])], ['Asignados', number_format($totals['assigned'])], ['Activos', number_format($totals['active'])], ['Saldo en circulación', \App\Support\Format::money($totals['circulating'])], ['Cobrado neto', \App\Support\Format::money($totals['redeemed'])]] as [$label, $value])
                <div class="rounded-md border border-slate-200 p-3"><div class="subheader">{{ $label }}</div><div class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</div></div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[960px]">
                <thead><tr>
                    <th class="text-left">Lote</th><th class="text-left">Creado</th><th class="text-left">Estado</th><th class="text-right">Cantidad</th>
                    <th class="text-right">Sin asignar</th><th class="text-right">Asignados</th><th class="text-right">Activos</th><th class="text-right">Anulados</th>
                    <th class="text-right">Saldo circ.</th><th class="text-right">Cobrado neto</th>
                </tr></thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td>
                                <a href="{{ route('admin.coupon-batches.show', $r->batch) }}" class="font-medium text-primary-600 hover:underline">{{ $r->batch->code }}</a>
                                <span class="block text-xs text-slate-500">{{ $r->batch->name }} · {{ $r->batch->coupon_type === 'QRCODE' ? 'QR' : 'Barras' }}</span>
                            </td>
                            <td>{{ $r->batch->created_at->format('d/m/Y') }}</td>
                            <td><x-admin.snow.badge field="status" :value="$r->batch->status" /></td>
                            <td class="text-right tabular-nums">{{ number_format($r->batch->quantity) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($r->generated) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($r->assigned) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($r->active) }}</td>
                            <td class="text-right tabular-nums">{{ number_format($r->void) }}</td>
                            <td class="text-right tabular-nums">{{ \App\Support\Format::money($r->circulating) }}</td>
                            <td class="text-right font-semibold tabular-nums">{{ \App\Support\Format::money($r->redeemed) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-10 text-center text-slate-500">No hay lotes en este periodo.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($truncated)<p class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">Se muestran los 500 lotes más recientes; acota las fechas para ver el resto.</p>@endif
    </div>
</x-admin.layouts.app>
