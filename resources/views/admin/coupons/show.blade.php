<x-admin.layouts.app :title="'Cupón '.$coupon->coupon_number">
    @php
        $money = fn ($v) => \App\Support\Format::money($v);
        $signed = fn ($v) => ($v >= 0 ? '+' : '−').\App\Support\Format::money(abs($v));
        $statuses = ['GENERATED' => 'Generado', 'ASSIGNED' => 'Asignado', 'ACTIVE' => 'Activo', 'INACTIVE' => 'Inactivo', 'VOID' => 'Anulado'];
        $statusTag = ['GENERATED' => 'bg-slate-100 text-slate-700', 'ASSIGNED' => 'bg-blue-50 text-blue-800', 'ACTIVE' => 'bg-green-50 text-green-800', 'INACTIVE' => 'bg-amber-50 text-amber-800', 'VOID' => 'bg-red-50 text-red-800'];
        $icons = ['created' => ['plus-circle', 'text-slate-500 bg-slate-100'], 'assigned' => ['user-plus', 'text-blue-700 bg-blue-50'], 'activated' => ['check-circle', 'text-green-700 bg-green-50'],
            'redeem' => ['shopping-cart', 'text-red-700 bg-red-50'], 'topup' => ['arrow-up-circle', 'text-green-700 bg-green-50'], 'reversal' => ['arrow-uturn-left', 'text-amber-700 bg-amber-50'],
            'adjust' => ['adjustments-horizontal', 'text-purple-700 bg-purple-50'], 'status' => ['arrow-path', 'text-slate-600 bg-slate-100'], 'change' => ['pencil-square', 'text-slate-600 bg-slate-100']];
        $val = fn ($v) => $v === null || $v === '' ? '—' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
        $btn = 'snow-btn-secondary snow-btn-sm';
        $item = $coupon;
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <a href="{{ route('admin.coupons.index') }}" class="text-sm text-primary-600 hover:underline">← Volver a cupones</a>
        <div class="flex flex-wrap gap-2">
            @if($can['printed'])
                {{-- Cupón impreso: se pide al abrirlo (el código no viaja en esta página) y cada consulta queda en la auditoría --}}
                <div
                    x-data="{
                        open: false, loading: false, error: '', c: null,
                        async show() {
                            this.open = true; this.loading = true; this.error = ''; this.c = null;
                            try {
                                const r = await fetch(@js(route('admin.coupons.printed', [$type, $coupon->getKey()])), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                                if (!r.ok) throw new Error();
                                this.c = await r.json();
                            } catch (e) { this.error = 'No se pudo cargar el cupón. Inténtalo de nuevo.'; }
                            this.loading = false;
                        },
                        close() { this.open = false; this.c = null; },
                    }"
                    @keydown.escape.window="close()"
                >
                    <button type="button" class="{{ $btn }}" @click="show()">Ver cupón</button>

                    <div x-show="open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/55 p-4 backdrop-blur-[2px]" @click.self="close()" role="dialog" aria-modal="true" aria-label="Cupón impreso">
                        <div class="w-full max-w-[340px] drop-shadow-2xl" @click.stop>
                            <div
                                class="relative overflow-hidden rounded-[16px] bg-white text-slate-900"
                                style="--stub: 74px; --notch: 12px;
                                       -webkit-mask: radial-gradient(circle var(--notch) at 0 calc(100% - var(--stub)), #0000 97%, #000), radial-gradient(circle var(--notch) at 100% calc(100% - var(--stub)), #0000 97%, #000);
                                       -webkit-mask-composite: source-in;
                                       mask: radial-gradient(circle var(--notch) at 0 calc(100% - var(--stub)), #0000 97%, #000), radial-gradient(circle var(--notch) at 100% calc(100% - var(--stub)), #0000 97%, #000);
                                       mask-composite: intersect;"
                            >
                                <div x-show="loading" class="px-5 py-16 text-center text-sm text-slate-500">Cargando cupón…</div>
                                <p x-show="error" class="px-5 py-12 text-center text-sm text-red-600" x-text="error"></p>

                                <template x-if="c">
                                    <div>
                                        <div class="flex items-center justify-between bg-slate-900 px-5 py-3 text-white">
                                            <span class="text-base font-extrabold uppercase tracking-[0.14em]" x-text="c.brand"></span>
                                            <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-300">Cupón prepago</span>
                                        </div>

                                        <div class="px-5 pb-4 pt-4 text-center">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">Valor</p>
                                            <p class="text-[38px] font-extrabold leading-none tabular-nums" x-text="c.amount"></p>

                                            {{-- El código tal como se imprime --}}
                                            <div class="mt-4 rounded-lg border border-slate-200 bg-white p-3">
                                                <div x-show="c.svg" x-html="c.svg" :class="c.kind === 'qr' ? '[&>svg]:mx-auto [&>svg]:h-auto [&>svg]:w-[210px]' : '[&>svg]:h-auto [&>svg]:w-full'"></div>
                                                <p x-show="c.note" class="rounded-md bg-amber-50 p-3 text-left text-xs leading-snug text-amber-900" x-text="c.note"></p>
                                                <p class="mt-2 font-mono text-[13px] font-semibold tracking-[0.22em] text-slate-800" x-text="c.number"></p>
                                            </div>

                                            <p x-show="c.status !== 'ACTIVE'" class="mt-3 rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700">
                                                Estado: <span x-text="({GENERATED: 'Generado (aún no activo)', ASSIGNED: 'Asignado (aún no activo)', INACTIVE: 'Inactivo', VOID: 'Anulado'})[c.status] || c.status"></span> · no se puede cobrar
                                            </p>
                                        </div>

                                        {{-- Talón --}}
                                        <div class="absolute inset-x-5 border-t-2 border-dashed border-slate-300" style="bottom: var(--stub);" aria-hidden="true"></div>
                                        <div class="flex items-center justify-between bg-slate-50 px-5" style="height: var(--stub);">
                                            <div>
                                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">Válido hasta</p>
                                                <p class="text-lg font-bold tabular-nums" x-text="c.expire || 'Sin vencimiento'"></p>
                                            </div>
                                            <button type="button" class="snow-btn-primary" @click="close()">Cerrar</button>
                                        </div>
                                    </div>
                                </template>

                                <div x-show="error || loading" class="border-t border-slate-100 bg-slate-50 px-5 py-3 text-right"><button type="button" class="snow-btn-secondary" @click="close()">Cerrar</button></div>
                            </div>
                            <p class="mt-2 text-center text-[11px] text-white/80">Este código permite cobrar el cupón: la consulta queda registrada en la auditoría.</p>
                        </div>
                    </div>
                </div>
            @endif
            @php $isActive = $coupon->status === 'ACTIVE'; @endphp
            @if(($isActive ? $can['deactivate'] : $can['activate']) && in_array($coupon->status, ['ACTIVE', 'INACTIVE'], true))
                <form method="POST" action="{{ route('admin.coupons.toggle', [$type, $coupon->getKey()]) }}"
                      onsubmit="const r = prompt('Motivo para {{ $isActive ? 'desactivar' : 'activar' }} este cupón:'); if (!r || r.trim().length < 3) return false; this.reason.value = r;">
                    @csrf<input type="hidden" name="active" value="{{ $isActive ? 0 : 1 }}"><input type="hidden" name="reason" value="">
                    <button type="submit" class="{{ $btn }}">{{ $isActive ? 'Desactivar' : 'Activar' }}</button>
                </form>
            @endif
            @if($can['topup'] && $coupon->status === 'ACTIVE')
                <form method="POST" action="{{ route('admin.coupons.topup', [$type, $coupon->getKey()]) }}"
                      onsubmit="const a = prompt('Monto a recargar:'); if (!a || isNaN(a) || Number(a) <= 0) return false; const n = prompt('Nota (opcional):') || ''; this.amount.value = a; this.note.value = n;">
                    @csrf<input type="hidden" name="amount" value=""><input type="hidden" name="note" value="">
                    <button type="submit" class="{{ $btn }}">Recargar</button>
                </form>
            @endif
            @if($can['replace'] && in_array($coupon->status, ['ACTIVE', 'INACTIVE'], true) && (float) $coupon->coupon_balance > 0 && ! $replacement['pending'])
                <form method="POST" action="{{ route('admin.coupons.replace', [$type, $coupon->getKey()]) }}"
                      onsubmit="if (!confirm('El cupón quedará anulado y su saldo ({{ $money($coupon->coupon_balance) }}) pasará a un cupón nuevo cuando otro administrador lo apruebe. ¿Continuar?')) return false; const r = prompt('Motivo (p. ej. cupón perdido, robado, dañado):'); if (!r || r.trim().length < 5) return false; this.reason.value = r;">
                    @csrf<input type="hidden" name="reason" value="">
                    <button type="submit" class="{{ $btn }}">Reemplazar (perdido)</button>
                </form>
            @endif
            @if($can['adjust'])
                <form method="POST" action="{{ route('admin.coupons.adjust', [$type, $coupon->getKey()]) }}"
                      onsubmit="const b = prompt('Nuevo saldo (máx. {{ number_format((float) $coupon->coupon_amount, 2, '.', '') }}):', '{{ number_format((float) $coupon->coupon_balance, 2, '.', '') }}'); if (b === null || b === '') return false; const r = prompt('Motivo del ajuste:'); if (!r || r.trim().length < 5) return false; this.balance.value = b; this.reason.value = r;">
                    @csrf<input type="hidden" name="balance" value=""><input type="hidden" name="reason" value="">
                    <button type="submit" class="{{ $btn }}">Ajustar saldo</button>
                </form>
            @endif
        </div>
    </div>

    @if(session('status'))<p class="mb-3 rounded border border-green-100 bg-green-50 px-4 py-2 text-sm text-green-800">{{ session('status') }}</p>@endif
    @if($errors->has('coupon'))<p class="mb-3 rounded border border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700">{{ $errors->first('coupon') }}</p>@endif
    @if($replacement['replacedBy'])
        <p class="mb-3 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">Este cupón se dio por perdido y fue reemplazado por
            <a class="font-mono font-semibold underline" href="{{ route('admin.coupons.show', [$replacement['replacedBy']->routeType(), $replacement['replacedBy']->new_coupon_id]) }}">{{ $replacement['replacedBy']->new_coupon_number }}</a>: ya no se puede cobrar.</p>
    @endif
    @if($replacement['replaces'])
        <p class="mb-3 rounded border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-900">Cupón de reemplazo: recibió el saldo del cupón
            <a class="font-mono font-semibold underline" href="{{ route('admin.coupons.show', [$replacement['replaces']->routeType(), $replacement['replaces']->old_coupon_id]) }}">{{ $replacement['replaces']->old_coupon_number }}</a>, que se dio por perdido.</p>
    @endif
    @if($replacement['pending'])
        <p class="mb-3 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">Hay un reemplazo de este cupón pendiente de aprobación (Cupones → Reemplazos de cupón).</p>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-1">
            {{-- Datos actuales --}}
            <section class="snow-card">
                <header class="flex items-center gap-2 border-b border-slate-200 px-5 py-3">
                    <x-admin.snow.icon :name="$couponType === 'QRCODE' ? 'qr-code' : 'ticket'" class="h-5 w-5 text-slate-400" />
                    <h2 class="text-base font-medium text-slate-800">{{ $couponType === 'QRCODE' ? 'Cupón QR' : 'Cupón de barras' }}</h2>
                    <span class="snow-tag ml-auto {{ $statusTag[$coupon->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $statuses[$coupon->status] ?? $coupon->status }}</span>
                </header>
                <div class="perf" style="margin-top: 0"></div>
                <dl class="space-y-3 p-5 text-sm">
                    <div><dt class="text-xs text-slate-500">Número</dt><dd class="break-all font-mono text-slate-900">{{ $coupon->coupon_number }}</dd></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><dt class="text-xs text-slate-500">Saldo actual</dt><dd class="text-xl font-semibold tabular-nums text-slate-900">{{ $money($coupon->coupon_balance) }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Monto inicial</dt><dd class="text-xl tabular-nums text-slate-700">{{ $money($initial) }}</dd></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><dt class="text-xs text-slate-500">Emisión</dt><dd class="text-slate-800">{{ $coupon->coupon_date ? \Carbon\Carbon::parse($coupon->coupon_date)->format('d/m/Y') : '—' }}</dd></div>
                        <div><dt class="text-xs text-slate-500">Vence</dt><dd class="text-slate-800">{{ $coupon->coupon_expire_date ? \Carbon\Carbon::parse($coupon->coupon_expire_date)->format('d/m/Y') : 'No vence' }}</dd></div>
                    </div>
                    <div><dt class="text-xs text-slate-500">Lote</dt><dd class="text-slate-800">@if($batch)@can('coupon_batches.view')<a href="{{ route('admin.coupon-batches.show', $batch) }}" class="text-primary-600 hover:underline">{{ $batch->code }}</a>@else{{ $batch->code }}@endcan — {{ $batch->name }}@if($coupon->seq) <span class="text-slate-500">(n.º {{ $coupon->seq }})</span>@endif @else Sin lote @endif</dd></div>
                    <div><dt class="text-xs text-slate-500">Cliente</dt><dd class="text-slate-800">{{ $customer?->name ?? 'Sin asignar' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Activado</dt><dd class="text-slate-800">{{ $coupon->activated_at ? \Carbon\Carbon::parse($coupon->activated_at)->format('d/m/Y H:i') : '—' }}</dd></div>
                </dl>
            </section>

            {{-- Reglas de uso asignadas --}}
            <section class="snow-card">
                <header class="border-b border-slate-200 px-5 py-3"><h2 class="text-base font-medium text-slate-800">Reglas de uso</h2></header>
                <div class="space-y-3 p-5 text-sm">
                    @forelse($rules as $r)
                        <div>
                            <p class="font-medium text-slate-900">{{ $r->name }}@if($r->isLegacy()) <span class="text-xs font-normal text-slate-500">(regla global anterior)</span>@endif</p>
                            @foreach($r->conditionsSummary() as $c)<p class="text-xs text-slate-600">{{ $c }}</p>@endforeach
                        </div>
                    @empty
                        <p class="text-slate-500">Ninguna regla de uso aplica a este cupón: se puede recargar sin restricciones.</p>
                    @endforelse
                </div>
            </section>

            {{-- Resumen de uso --}}
            <section class="snow-card">
                <header class="border-b border-slate-200 px-5 py-3"><h2 class="text-base font-medium text-slate-800">Resumen de uso</h2></header>
                <dl class="space-y-2 p-5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Cobrado neto</dt><dd class="font-medium tabular-nums text-slate-900">{{ $money($summary['redeemed']) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Recargado</dt><dd class="font-medium tabular-nums text-slate-900">{{ $money($summary['topups']) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Movimientos de dinero</dt><dd class="font-medium tabular-nums text-slate-900">{{ number_format($summary['transactions']) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Último cobro</dt><dd class="text-slate-900">{{ $summary['last_use']?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-slate-500">Localidades donde se usó</dt><dd class="mt-1 flex flex-wrap gap-1">@forelse($summary['locations'] as $l)<span class="snow-tag bg-slate-100 text-slate-700">{{ $l }}</span>@empty<span class="text-slate-500">—</span>@endforelse</dd></div>
                    @unless($balanceCheck)
                        <p class="rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">El saldo calculado con el historial no coincide con el saldo actual (puede haber movimientos anteriores al registro o importados).</p>
                    @endunless
                </dl>
            </section>
        </div>

        {{-- Línea de tiempo --}}
        <section class="snow-card lg:col-span-2">
            <header class="border-b border-slate-200 px-5 py-3">
                <h2 class="text-base font-medium text-slate-800">Historial completo</h2>
                <p class="mt-0.5 text-sm text-slate-500">Desde su creación: movimientos, cobros, recargas, ajustes y cambios, con quién, dónde y el saldo resultante. Lo más reciente primero.</p>
            </header>
            <ol class="divide-y divide-slate-100">
                @foreach($events as $e)
                    @php [$icon, $tone] = $icons[$e['kind']] ?? ['information-circle', 'text-slate-500 bg-slate-100']; @endphp
                    <li class="flex gap-3 px-5 py-3" x-data="{ open: false }">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $tone }}"><x-admin.snow.icon :name="$icon" class="h-4 w-4" /></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                <p class="font-medium text-slate-900">{{ $e['title'] }}</p>
                                <span class="whitespace-nowrap text-xs tabular-nums text-slate-500">{{ $e['at']?->format('d/m/Y H:i:s') ?? '—' }}</span>
                            </div>
                            @if(isset($e['amount']))
                                <p class="text-sm tabular-nums {{ $e['amount'] >= 0 ? 'text-green-700' : 'text-red-700' }}">{{ $signed($e['amount']) }}
                                    <span class="text-slate-500">· saldo después: {{ $money($e['balance']) }}</span></p>
                            @elseif(isset($e['balance']) && $e['kind'] !== 'created')
                                <p class="text-sm tabular-nums text-slate-500">Saldo después: {{ $money($e['balance']) }}</p>
                            @endif
                            @foreach($e['lines'] as $line)<p class="text-xs text-slate-600">{{ $line }}</p>@endforeach
                            @if(!empty($e['actor']))<p class="text-xs text-slate-500">Por: <span class="font-medium text-slate-700">{{ $e['actor'] }}</span></p>@endif
                            @if(!empty($e['changes']))
                                <button type="button" class="mt-1 text-xs text-primary-600 hover:underline" x-on:click="open = !open" x-text="open ? 'Ocultar antes y después' : 'Ver antes y después'"></button>
                                <table x-show="open" x-cloak class="mt-1 w-full text-left text-xs">
                                    <thead class="snow-table-head"><tr><th class="px-2 py-1">Campo</th><th class="px-2 py-1">Antes</th><th class="px-2 py-1">Después</th></tr></thead>
                                    <tbody class="divide-y divide-slate-100">@foreach($e['changes'] as $field => $pair)<tr><td class="px-2 py-1 font-medium text-slate-700">{{ $field }}</td><td class="break-all px-2 py-1 text-red-700">{{ $val($pair[0] ?? null) }}</td><td class="break-all px-2 py-1 text-green-700">{{ $val($pair[1] ?? null) }}</td></tr>@endforeach</tbody>
                                </table>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            @if($truncated)<p class="border-t border-slate-200 px-5 py-2 text-xs text-slate-500">Se muestran los {{ 500 }} movimientos más recientes de cada tipo.</p>@endif
        </section>
    </div>

    {{-- Accesos al cupón --}}
    @if($views->isNotEmpty())
        <section class="snow-card mt-4" x-data="{ open: false }">
            <header class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <h2 class="text-base font-medium text-slate-800">Quién consultó este cupón <span class="text-sm font-normal text-slate-500">({{ $views->count() }} {{ $views->count() === 1 ? 'acceso' : 'accesos' }} recientes)</span></h2>
                <button type="button" class="text-sm text-primary-600 hover:underline" x-on:click="open = !open" x-text="open ? 'Ocultar' : 'Mostrar'"></button>
            </header>
            <ul x-show="open" x-cloak class="divide-y divide-slate-100 text-sm">
                @foreach($views as $v)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-5 py-2"><span><span class="font-medium text-slate-800">{{ $v->admin_name ?? 'Sistema' }}</span> <span class="text-slate-600">— {{ $v->summary }}</span></span><span class="text-xs tabular-nums text-slate-500">{{ $v->created_at?->format('d/m/Y H:i:s') }} · {{ $v->ip }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-admin.layouts.app>
