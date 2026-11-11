<x-admin.layouts.app :title="'Inicio'">
    <div class="mx-auto mt-2 max-w-5xl">
        <h2 class="text-sm font-semibold text-slate-900">Bienvenido, {{ auth('admin')->user()->name }}</h2>

        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach ($kpis as $kpi)
                <div class="snow-card rounded-xl border border-slate-200/90 bg-white p-4 shadow-hope-card">
                    <div class="text-[11px] text-slate-500">{{ $kpi['label'] }}</div>
                    <div class="mt-1 text-xl font-semibold text-slate-900">{{ $kpi['value'] }}</div>
                </div>
            @endforeach
        </div>

        <h3 class="mt-5 text-[10px] font-mono font-bold uppercase tracking-widest text-slate-500">Últimos cobros</h3>
        <div class="snow-card mt-2 overflow-x-auto rounded-xl border border-slate-200/90 bg-white shadow-hope-card">
            <table class="w-full border-collapse text-left text-[11px]">
                <thead class="bg-slate-50"><tr>
                    <th class="px-2 py-1.5">Fecha</th><th class="px-2 py-1.5">Cupón</th><th class="px-2 py-1.5">Tipo</th>
                    <th class="px-2 py-1.5 text-right">Monto</th><th class="px-2 py-1.5">Usuario</th><th class="px-2 py-1.5">Localidad</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($recent as $tx)
                        <tr>
                            <td class="px-2 py-1">{{ $tx->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-2 py-1 font-mono">{{ $tx->coupon_number }}</td>
                            <td class="px-2 py-1">{{ $tx->coupon_type === 'QRCODE' ? 'QR' : 'Barras' }}</td>
                            <td class="px-2 py-1 text-right tabular-nums {{ (float) $tx->transaction_amount < 0 ? 'text-rose-700' : '' }}">{{ \App\Support\Format::money($tx->transaction_amount) }}</td>
                            <td class="px-2 py-1">{{ $tx->user?->username ?? '—' }}</td>
                            <td class="px-2 py-1">{{ $tx->location?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-2 py-3 text-center text-slate-500">Aún no hay cobros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <h3 class="mt-5 text-[10px] font-mono font-bold uppercase tracking-widest text-slate-500">Infraestructura</h3>
        <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($stats as $stat)
                <div class="snow-card rounded-xl border border-slate-200/90 bg-white p-3 shadow-hope-card">
                    <div class="text-[11px] text-slate-500">{{ $stat['label'] }}</div>
                    <div class="mt-1 text-lg font-semibold text-slate-900">{{ $stat['value'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
</x-admin.layouts.app>
