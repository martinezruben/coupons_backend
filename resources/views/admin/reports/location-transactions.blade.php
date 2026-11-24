<x-admin.layouts.app title="Transacciones por localidad">
    @php
        $money = fn ($v) => \App\Support\Format::money($v);
        $kindTag = ['sold' => 'bg-green-50 text-green-800', 'reversed' => 'bg-amber-50 text-amber-800', 'reversal' => 'bg-amber-50 text-amber-800', 'topup' => 'bg-blue-50 text-blue-800'];
        $sortLink = function (string $key) use ($sort, $dir) {
            $next = $sort === $key && $dir === 'asc' ? 'desc' : 'asc';

            return request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null]);
        };
        $th = fn (string $key, string $label, string $class = 'text-left') => '<th class="'.$class.'"><a href="'.e($sortLink($key)).'" class="inline-flex items-center gap-1 hover:text-slate-900">'.$label.($sort === $key ? '<span aria-hidden="true">'.($dir === 'asc' ? '↑' : '↓').'</span>' : '').'</a></th>';
    @endphp

    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">
                Todo lo consumido en <strong>{{ $scopeLabel }}</strong> (cambia la localidad en el selector de la barra superior). Cobrado = cobros; neto = cobrado menos reversas; las recargas van aparte.
                @unless($canFull) Los números de cupón de barras salen enmascarados. @endunless
            </p>
        </div>

        <form method="GET" action="{{ route('admin.reports.location-transactions') }}" class="grid grid-cols-2 items-end gap-2 border-b border-slate-200 p-3 sm:grid-cols-4 lg:grid-cols-6" x-data="{ period: '{{ $period }}' }">
            <div class="col-span-2">
                <label class="snow-label" for="q">Buscar</label>
                <input id="q" name="q" type="search" value="{{ $f['q'] ?? '' }}" class="snow-input" placeholder="N.º de cupón, transacción, venta o cajero">
            </div>
            <div>
                <label class="snow-label" for="period">Periodo</label>
                <select id="period" name="period" class="snow-input" x-model="period">@foreach($periods as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            </div>
            <div x-show="period === 'custom'" x-cloak><label class="snow-label" for="from">Desde</label><input id="from" name="from" type="datetime-local" value="{{ $from }}" class="snow-input" :disabled="period !== 'custom'"></div>
            <div x-show="period === 'custom'" x-cloak><label class="snow-label" for="to">Hasta</label><input id="to" name="to" type="datetime-local" value="{{ $to }}" class="snow-input" :disabled="period !== 'custom'"></div>
            <div>
                <label class="snow-label" for="status">Estado</label>
                <select id="status" name="status" class="snow-input"><option value="">Todos</option>@foreach($statuses as $k => $l)<option value="{{ $k }}" @selected(($f['status'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="coupon_type">Tipo de cupón</label>
                <select id="coupon_type" name="coupon_type" class="snow-input"><option value="">Todos</option><option value="QRCODE" @selected(($f['coupon_type'] ?? '') === 'QRCODE')>QR</option><option value="CODBAR" @selected(($f['coupon_type'] ?? '') === 'CODBAR')>Código de barras</option></select>
            </div>
            <div>
                <label class="snow-label" for="shift_id">Turno</label>
                <select id="shift_id" name="shift_id" class="snow-input"><option value="">Todos</option>@foreach($shifts as $s)<option value="{{ $s->id }}" @selected((string) ($f['shift_id'] ?? '') === (string) $s->id)>{{ $multiLocation ? ($s->location?->name.' · ') : '' }}Turno {{ $s->number }} · {{ $s->starts_at->format('d/m H:i') }}{{ $s->isOpen() ? ' (abierto)' : '' }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="user_id">Cajero</label>
                <select id="user_id" name="user_id" class="snow-input"><option value="">Todos</option>@foreach($cashiers as $id => $name)<option value="{{ $id }}" @selected(($f['user_id'] ?? '') === (string) $id)>{{ $name }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="device_id">Dispositivo</label>
                <select id="device_id" name="device_id" class="snow-input"><option value="">Todos</option>@foreach($devices as $d)<option value="{{ $d->id }}" @selected(($f['device_id'] ?? '') === (string) $d->id)>{{ $d->name ?: \Illuminate\Support\Str::limit($d->device_fingerprint, 14) }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="dispenser">Surtidor</label>
                <select id="dispenser" name="dispenser" class="snow-input"><option value="">Todos</option><option value="none" @selected(($f['dispenser'] ?? '') === 'none')>Sin surtidor</option>@foreach(range(1, 20) as $n)<option value="{{ $n }}" @selected((string) ($f['dispenser'] ?? '') === (string) $n)>Surtidor {{ $n }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="customer_id">Cliente</label>
                <select id="customer_id" name="customer_id" class="snow-input"><option value="">Todos</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected(($f['customer_id'] ?? '') === (string) $c->id)>{{ $c->name }}</option>@endforeach</select>
            </div>
            <div><label class="snow-label" for="min">Monto desde</label><input id="min" name="min" type="number" step="0.01" min="0" value="{{ $f['min'] ?? '' }}" class="snow-input"></div>
            <div><label class="snow-label" for="max">Monto hasta</label><input id="max" name="max" type="number" step="0.01" min="0" value="{{ $f['max'] ?? '' }}" class="snow-input"></div>
            <div>
                <label class="snow-label" for="per_page">Por página</label>
                <select id="per_page" name="per_page" class="snow-input">@foreach([25, 50, 100] as $n)<option value="{{ $n }}" @selected((int) ($f['per_page'] ?? 25) === $n)>{{ $n }}</option>@endforeach</select>
            </div>
            <div class="col-span-full flex flex-wrap items-center gap-2">
                <button type="submit" class="snow-btn-primary">Filtrar</button>
                <a href="{{ route('admin.reports.location-transactions') }}" class="snow-btn-secondary">Limpiar</a>
                @can('coupon_location_transactions.export')<a href="{{ $exportUrl }}" class="snow-btn-secondary" title="Descarga todo lo filtrado, sin paginar (se abre en Excel)">Exportar CSV</a>@endcan
                <span class="ml-auto text-xs text-slate-500">{{ \Carbon\Carbon::parse($from)->format('d/m/Y H:i') }} → {{ \Carbon\Carbon::parse($to)->format('d/m/Y H:i') }}</span>
            </div>
            @if($errors->any())<p class="col-span-full text-xs text-red-600">{{ $errors->first() }}</p>@endif
        </form>

        <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-5">
            @foreach([['Movimientos', number_format($totals['count'])], ['Cobrado', $money($totals['redeemed'])], ['Reversado', $money($totals['reversed'])], ['Neto cobrado', $money($totals['net'])], ['Recargado', $money($totals['topups'])]] as [$label, $value])
                <div class="rounded-md border border-slate-200 p-3 {{ $label === 'Neto cobrado' ? 'bg-slate-900 text-white' : '' }}">
                    <div class="text-[10px] font-bold uppercase tracking-[0.12em] {{ $label === 'Neto cobrado' ? 'text-slate-300' : 'text-slate-500' }}">{{ $label }}</div>
                    <div class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[1100px]">
                <thead><tr>
                    {!! $th('created_at', 'Fecha y hora') !!}{!! $th('transaction_id', 'N.º') !!}
                    <th class="text-left">Estado</th>{!! $th('coupon_number', 'Cupón') !!}
                    {!! $th('transaction_amount', 'Monto', 'text-right') !!}<th class="text-right">Saldo después</th>
                    @if($multiLocation)<th class="text-left">Localidad</th>@endif
                    <th class="text-left">Turno</th><th class="text-left">Surtidor</th><th class="text-left">Cajero</th><th class="text-left">Dispositivo</th><th class="text-left">Cliente</th>
                </tr></thead>
                <tbody>
                @forelse($rows as $r)
                    @php
                        $key = $r->kind === 'REDEEM' ? ($r->is_reversed ? 'reversed' : 'sold') : ($r->kind === 'REVERSAL' ? 'reversal' : 'topup');
                        $number = $r->coupon_type === 'CODBAR' && ! $canFull ? \App\Support\SensitiveData::maskNumber((string) $r->coupon_number) : $r->coupon_number;
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $r->created_at?->format('d/m/Y H:i:s') }}</td>
                        <td class="px-3 py-2 tabular-nums text-slate-500">{{ $r->transaction_id }}</td>
                        <td class="px-3 py-2"><span class="snow-tag {{ $kindTag[$key] }}">{{ ['sold' => 'Cobro', 'reversed' => 'Cobro revertido', 'reversal' => 'Reversa', 'topup' => 'Recarga'][$key] }}</span></td>
                        <td class="px-3 py-2 font-mono text-xs"><span class="inline-flex items-center gap-1.5"><x-admin.snow.icon :name="$r->coupon_type === 'QRCODE' ? 'qr-code' : 'ticket'" class="h-4 w-4 text-slate-400" />{{ $number }}</span></td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums {{ (float) $r->transaction_amount < 0 ? 'text-amber-800' : '' }}">{{ $money($r->transaction_amount) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-slate-600">{{ $r->balance_after !== null ? $money($r->balance_after) : '—' }}</td>
                        @if($multiLocation)<td class="px-3 py-2">{{ $r->location_name ?? '—' }}</td>@endif
                        <td class="whitespace-nowrap px-3 py-2">{{ $r->shift_number ? 'Turno '.$r->shift_number : '—' }}</td>
                        <td class="px-3 py-2">{{ $r->dispenser ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $r->cashier ?? ($r->admin_name ? 'Panel: '.$r->admin_name : '—') }}</td>
                        <td class="px-3 py-2 text-xs text-slate-600">{{ $r->device_name ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $r->customer_name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $multiLocation ? 12 : 11 }}" class="px-4 py-10 text-center text-slate-500">No hay movimientos con esos filtros.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-3">{{ $rows->links() }}</div>
    </div>
</x-admin.layouts.app>
