<x-admin.layouts.app :title="'Inicio'">
    <div class="-mt-4 mb-6 flex flex-wrap items-end justify-between gap-3">
        <p class="text-slate-500">Bienvenido, {{ auth('admin')->user()->name }}.</p>
        <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap items-end gap-2">
            <div>
                <label class="snow-label" for="days">Periodo</label>
                <select id="days" name="days" class="snow-input" onchange="this.form.submit()">
                    @foreach($periods as $p)
                        <option value="{{ $p }}" @selected($days === $p)>Últimos {{ $p }} días</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="snow-label" for="customer">Cliente</label>
                <select id="customer" name="customer" class="snow-input" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected($customerId === $customer->id)>{{ $customer->name }}</option>
                    @endforeach
                </select>
            </div>
            <noscript><button type="submit" class="snow-btn-secondary">Aplicar</button></noscript>
        </form>
    </div>

    @php
        $k = collect($kpis)->keyBy('label');
        $hero = $k->get('Saldo en circulación');
        $heroSide = collect(['Activos', 'Cobrado hoy'])->map(fn ($l) => $k->get($l))->filter();
        $tiles = collect($kpis)->reject(fn ($x) => in_array($x['label'], ['Saldo en circulación', 'Activos', 'Cobrado hoy'], true));
    @endphp
    <section class="flex flex-wrap gap-6" aria-label="Resumen">
        <article class="flex flex-1 basis-[420px] flex-col rounded-[24px] bg-primary-600 text-white">
            <div class="flex flex-col gap-1.5 px-7 pb-6 pt-7">
                <span class="eyebrow !text-[#B9D3C5]">{{ $hero['label'] ?? 'Saldo en circulación' }}</span>
                <span class="disp text-[clamp(2.6rem,5vw,3.75rem)] font-bold leading-[1.05] text-lime-300">{{ $hero['value'] ?? '$0.00' }}</span>
                <span class="text-sm text-[#C9DDD0]">{{ $hero['hint'] ?? '' }}</span>
            </div>
            <div class="perf" style="border-top-color: rgba(255,255,255,.28)"></div>
            <div class="flex flex-wrap gap-7 px-7 pb-6 pt-5">
                @foreach($heroSide as $x)
                    <div class="flex flex-col"><span class="eyebrow !text-[#B9D3C5]">{{ $x['label'] }}</span><span class="disp text-3xl font-bold">{{ $x['value'] }}</span><span class="text-[13px] text-[#C9DDD0]">{{ $x['hint'] }}</span></div>
                @endforeach
            </div>
        </article>
        <div class="grid flex-1 basis-[520px] grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-4">
            @foreach($tiles as $x)
                <div class="tile"><span class="eyebrow">{{ $x['label'] }}</span><span class="v">{{ $x['value'] }}</span><span class="s">{{ $x['hint'] }}</span></div>
            @endforeach
        </div>
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="snow-card lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-display text-xl font-semibold text-slate-900">Cobrado y recargado por día</h2></div>
            <div class="p-3"><div id="chart-redeemed" role="img" aria-label="Gráfica de barras del cobrado neto y lo recargado por día"></div></div>
        </div>
        <div class="snow-card">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-display text-xl font-semibold text-slate-900">Estado de los cupones</h2></div>
            <div class="p-3"><div id="chart-status" role="img" aria-label="Gráfica de dona con los cupones por estado"></div></div>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="snow-card lg:col-span-2">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-display text-xl font-semibold text-slate-900">Cupones activados por día</h2></div>
            <div class="p-3"><div id="chart-activated" role="img" aria-label="Gráfica de área con los cupones activados por día"></div></div>
        </div>
        <div class="snow-card">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-display text-xl font-semibold text-slate-900">Clientes con más saldo activo</h2></div>
            <table>
                <thead><tr><th class="text-left">Cliente</th><th class="text-right">Activos</th><th class="text-right">Saldo</th></tr></thead>
                <tbody>
                    @forelse($topCustomers as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="text-right tabular-nums">{{ number_format($row['count']) }}</td>
                            <td class="text-right tabular-nums">{{ \App\Support\Format::money($row['balance']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-8 text-center text-slate-500">Aún no hay cupones activos con cliente.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="snow-card">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 class="font-display text-xl font-semibold text-slate-900">Próximas activaciones</h2>
                <a href="{{ route('admin.screens.index', 'coupon-activations') }}?filter[status]=pending" class="text-sm text-primary-600 hover:underline">Ver todas</a>
            </div>
            <table>
                <thead><tr><th class="text-left">Activar el</th><th class="text-left">Lote</th><th class="text-left">Cliente</th><th class="text-right">Cupones</th></tr></thead>
                <tbody>
                    @forelse($upcoming as $order)
                        <tr>
                            <td>{{ $order->activate_at?->format('d/m/Y H:i') }}</td>
                            <td><a href="{{ route('admin.coupon-batches.show', $order->batch_id) }}" class="text-primary-600 hover:underline">{{ $order->batch?->code }}</a></td>
                            <td>{{ $order->customer?->name ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($order->coupons_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-slate-500">No hay activaciones programadas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="snow-card">
            <div class="border-b border-slate-200 px-5 py-4"><h2 class="font-display text-xl font-semibold text-slate-900">Últimos movimientos</h2></div>
            <table>
                <thead><tr><th class="text-left">Fecha</th><th class="text-left">Cupón</th><th class="text-left">Movimiento</th><th class="text-right">Monto</th></tr></thead>
                <tbody>
                    @forelse($recent as $tx)
                        <tr>
                            <td>{{ $tx->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="font-mono text-xs">{{ $tx->coupon_number }}</td>
                            <td><x-admin.snow.badge field="kind" :value="$tx->kind" /></td>
                            <td class="text-right tabular-nums {{ (float) $tx->transaction_amount < 0 ? 'text-red-700' : '' }}">{{ \App\Support\Format::money($tx->transaction_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-slate-500">Aún no hay movimientos. Cuando la app registre el primero, aparecerá aquí.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script type="application/json" id="dashboard-data">@json($charts)</script>
    @push('scripts')
        @vite(['resources/js/dashboard.js'])
    @endpush
</x-admin.layouts.app>
