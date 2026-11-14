<x-admin.layouts.app title="Cupones">
    @php
        $statuses = ['GENERATED' => 'Generado', 'ASSIGNED' => 'Asignado', 'ACTIVE' => 'Activo', 'INACTIVE' => 'Inactivo', 'VOID' => 'Anulado'];
        $statusTag = ['GENERATED' => 'bg-slate-100 text-slate-700', 'ASSIGNED' => 'bg-blue-50 text-blue-800', 'ACTIVE' => 'bg-green-50 text-green-800', 'INACTIVE' => 'bg-amber-50 text-amber-800', 'VOID' => 'bg-red-50 text-red-800'];
        $sortLink = function (string $key) use ($sort, $dir) {
            $next = $sort === $key && $dir === 'asc' ? 'desc' : 'asc';

            return request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null]);
        };
        $th = fn (string $key, string $label, string $class = 'text-left') => '<th class="'.$class.'"><a href="'.e($sortLink($key)).'" class="inline-flex items-center gap-1 hover:text-slate-900">'.$label.($sort === $key ? '<span aria-hidden="true">'.($dir === 'asc' ? '↑' : '↓').'</span>' : '').'</a></th>';
    @endphp
    <div class="snow-card">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">
                Cupones QR y de barras en una sola lista. El icono junto al número indica el tipo
                (<x-admin.snow.icon name="qr-code" class="inline h-4 w-4 text-slate-500" /> QR, <x-admin.snow.icon name="ticket" class="inline h-4 w-4 text-slate-500" /> barras).
                Haz clic en un cupón para ver todo su historial.
            </p>
        </div>

        <form method="GET" action="{{ route('admin.coupons.index') }}" class="grid grid-cols-2 items-end gap-2 border-b border-slate-200 p-3 sm:grid-cols-4 lg:grid-cols-8">
            <div class="col-span-2">
                <label class="snow-label" for="q">Número</label>
                <input id="q" name="q" type="search" value="{{ $f['q'] ?? '' }}" class="snow-input" placeholder="Buscar por número">
            </div>
            <div>
                <label class="snow-label" for="type">Tipo</label>
                <select id="type" name="type" class="snow-input">
                    <option value="">Todos</option>
                    @foreach(\App\Http\Controllers\Admin\CouponListController::TYPES as $key => $t)
                        @if($allowedTypes->contains($key))<option value="{{ $key }}" @selected(($f['type'] ?? '') === $key)>{{ $t['label'] === 'QR' ? 'QR' : 'Código de barras' }}</option>@endif
                    @endforeach
                </select>
            </div>
            <div>
                <label class="snow-label" for="status">Estado</label>
                <select id="status" name="status" class="snow-input"><option value="">Todos</option>@foreach($statuses as $k => $l)<option value="{{ $k }}" @selected(($f['status'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="customer">Cliente</label>
                <select id="customer" name="customer" class="snow-input"><option value="">Todos</option>@foreach($filterCustomers as $c)<option value="{{ $c->id }}" @selected(($f['customer'] ?? '') === $c->id)>{{ $c->name }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="batch">Lote</label>
                <select id="batch" name="batch" class="snow-input"><option value="">Todos</option>@foreach($filterBatches as $b)<option value="{{ $b->id }}" @selected(($f['batch'] ?? '') === $b->id)>{{ $b->code }} — {{ \Illuminate\Support\Str::limit($b->name, 24) }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="expire_to">Vence hasta</label>
                <input id="expire_to" name="expire_to" type="date" value="{{ $f['expire_to'] ?? '' }}" class="snow-input">
            </div>
            <div class="col-span-2 flex gap-2 sm:col-span-4 lg:col-span-8">
                <button type="submit" class="snow-btn-primary">Filtrar</button>
                <a href="{{ route('admin.coupons.index') }}" class="snow-btn-secondary">Limpiar</a>
                <span class="ml-auto self-center text-xs text-slate-500">{{ number_format($coupons->total()) }} cupones</span>
            </div>
            @if($errors->any())<p class="col-span-full text-xs text-red-600">{{ $errors->first() }}</p>@endif
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-[980px]">
                <thead><tr>
                    {!! $th('number', 'Número') !!}{!! $th('status', 'Estado') !!}
                    <th class="text-left">Lote</th><th class="text-left">Cliente</th>
                    {!! $th('expire', 'Vence') !!}{!! $th('activated', 'Activado') !!}
                    {!! $th('amount', 'Monto inicial', 'text-right') !!}{!! $th('balance', 'Saldo', 'text-right') !!}<th class="w-10"></th>
                </tr></thead>
                <tbody>
                @forelse($coupons as $c)
                    @php
                        $slug = \App\Http\Controllers\Admin\CouponListController::TYPES[$c->ctype]['slug'];
                        $url = route('admin.coupons.show', [$slug, $c->coupon_id]);
                    @endphp
                    <tr class="cursor-pointer hover:bg-slate-50" onclick="if (!event.target.closest('a')) window.location = '{{ $url }}'">
                        <td class="whitespace-nowrap px-3 py-2">
                            <a href="{{ $url }}" class="inline-flex items-center gap-2 font-medium text-primary-600 hover:underline">
                                <x-admin.snow.icon :name="$c->ctype === 'QRCODE' ? 'qr-code' : 'ticket'" class="h-4 w-4 shrink-0 text-slate-400" title="{{ $c->ctype === 'QRCODE' ? 'Cupón QR' : 'Cupón de barras' }}" />
                                <span class="font-mono text-xs">{{ $c->coupon_number }}</span>
                            </a>
                        </td>
                        <td class="px-3 py-2"><span class="snow-tag {{ $statusTag[$c->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statuses[$c->status] ?? $c->status }}</span></td>
                        <td class="px-3 py-2 text-slate-700">{{ $c->batch_id ? ($batchCodes[$c->batch_id] ?? '—') : '—' }}</td>
                        <td class="px-3 py-2 text-slate-700">{{ $c->customer_id ? ($customerNames[$c->customer_id] ?? '—') : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">{{ $c->coupon_expire_date ? \Carbon\Carbon::parse($c->coupon_expire_date)->format('d/m/Y') : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">{{ $c->activated_at ? \Carbon\Carbon::parse($c->activated_at)->format('d/m/Y H:i') : '—' }}</td>
                        <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ $money($c->coupon_amount) }}</td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums text-slate-900">{{ $money($c->coupon_balance) }}</td>
                        <td class="px-2 py-2 text-right"><a href="{{ $url }}" class="snow-icon-btn h-6 w-6" title="Ver historial completo"><x-admin.snow.icon name="eye" class="h-4 w-4" /></a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">No hay cupones con esos filtros.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-3">{{ $coupons->links() }}</div>
    </div>
</x-admin.layouts.app>
