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
            <div class="flex gap-2 lg:col-span-2"><button type="submit" class="snow-btn-primary">Ver</button>@can('coupon_batch_report.export')<a href="{{ $exportUrl }}" class="snow-btn-secondary">CSV</a>@endcan</div>
            @if($errors->any())<p class="col-span-full text-xs text-red-600">{{ $errors->first() }}</p>@endif
        </form>

        <div class="grid grid-cols-2 gap-3.5 p-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach([['Cupones', number_format($totals['quantity']), 'en el periodo'], ['Sin asignar', number_format($totals['generated']), 'aún sin cliente'], ['Asignados', number_format($totals['assigned']), 'reservados'], ['Activos', number_format($totals['active']), 'cobrables ahora'], ['Saldo en circulación', \App\Support\Format::money($totals['circulating']), 'de los activos'], ['Cobrado neto', \App\Support\Format::money($totals['redeemed']), 'neto de reversas']] as [$label, $value, $hint])
                <div class="tile"><span class="eyebrow">{{ $label }}</span><span class="v !text-[1.6rem]">{{ $value }}</span><span class="s">{{ $hint }}</span></div>
            @endforeach
        </div>

        <div class="flex flex-col gap-4 border-t border-slate-200 bg-slate-50 p-4">
            <span class="eyebrow">{{ $rows->count() }} {{ $rows->count() === 1 ? 'lote' : 'lotes' }}</span>
            @forelse($rows as $r)
                @php $q = max(1, $r->batch->quantity); @endphp
                <article class="ticket">
                    <div class="flex basis-[250px] flex-col justify-center gap-2 px-6 py-5">
                        <a href="{{ route('admin.coupon-batches.show', $r->batch) }}" class="font-mono text-base font-medium text-primary-600 hover:underline">{{ $r->batch->code }}</a>
                        <span class="text-sm text-slate-600">{{ $r->batch->name }} · {{ $r->batch->coupon_type === 'QRCODE' ? 'QR' : 'Barras' }}</span>
                        <div class="flex items-center gap-2.5"><x-admin.snow.badge field="status" :value="$r->batch->status" /><span class="font-mono text-xs text-slate-500">{{ $r->batch->created_at->format('d/m/Y') }}</span></div>
                    </div>
                    <div class="perfv"></div>
                    <div class="flex min-w-[260px] flex-[1_1_380px] flex-col justify-center gap-3.5 px-6 py-5">
                        <div class="seg" role="img" aria-label="Cantidad {{ $r->batch->quantity }}: {{ $r->generated }} sin asignar, {{ $r->assigned }} asignados, {{ $r->active }} activos">
                            <div style="flex: {{ $r->generated }}; background: #C9D1C5"></div><div style="flex: {{ $r->assigned }}; background: #74C58D"></div><div style="flex: {{ $r->active }}; background: #1E9E5A"></div><div style="flex: {{ $r->void + $r->inactive }}; background: #E15B4E"></div>
                        </div>
                        <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                            <span><span class="font-mono font-medium">{{ number_format($r->batch->quantity) }}</span> cantidad</span>
                            <span><span class="font-mono font-medium">{{ number_format($r->generated) }}</span> sin asignar</span>
                            <span><span class="font-mono font-medium">{{ number_format($r->assigned) }}</span> asignados</span>
                            <span><span class="font-mono font-medium">{{ number_format($r->active) }}</span> activos</span>
                            <span><span class="font-mono font-medium">{{ number_format($r->void) }}</span> anulados</span>
                        </div>
                        <div class="text-xs text-slate-600">
                            <span class="eyebrow">Dónde se canjeó</span>
                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5">@forelse(array_slice($r->locations, 0, 3) as $l)<span class="whitespace-nowrap">{{ $l['name'] }} · {{ \App\Support\Format::money($l['amount']) }}</span>@empty<span class="text-slate-400">—</span>@endforelse @if(count($r->locations) > 3)<span class="text-slate-400">+ {{ count($r->locations) - 3 }} más (en el CSV)</span>@endif</div>
                        </div>
                    </div>
                    <div class="perfv"></div>
                    <div class="flex basis-[220px] flex-col justify-center gap-3 px-6 py-5">
                        <div><span class="eyebrow">Saldo circ.</span><div class="font-display text-2xl font-bold">{{ \App\Support\Format::money($r->circulating) }}</div></div>
                        <div><span class="eyebrow">Cobrado neto</span><div class="font-display text-2xl font-bold">{{ \App\Support\Format::money($r->redeemed) }}</div></div>
                    </div>
                </article>
            @empty
                <p class="py-10 text-center text-slate-500">No hay lotes en este periodo.</p>
            @endforelse
        </div>
        @if($truncated)<p class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">Se muestran los 500 lotes más recientes; acota las fechas para ver el resto.</p>@endif
    </div>
</x-admin.layouts.app>
