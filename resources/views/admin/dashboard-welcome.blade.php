<x-admin.layouts.app :title="'Inicio'">
    <p class="-mt-4 mb-6 text-slate-500">Bienvenido, {{ auth('admin')->user()->name }}.</p>

    <div class="grid grid-cols-2 gap-6 lg:grid-cols-4">
        @foreach ($kpis as $kpi)
            <div class="snow-card p-5">
                <div class="subheader">{{ $kpi['label'] }}</div>
                <div class="stat-value">{{ $kpi['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="snow-card mt-6">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-medium text-slate-800">Últimos cobros</h2>
        </div>
        <div class="overflow-x-auto">
            <table>
                <thead>
                    <tr>
                        <th class="text-left">Fecha</th>
                        <th class="text-left">Cupón</th>
                        <th class="text-left">Tipo</th>
                        <th class="text-right">Monto</th>
                        <th class="text-left">Usuario</th>
                        <th class="text-left">Localidad</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recent as $tx)
                        <tr>
                            <td>{{ $tx->created_at?->format('d/m/Y H:i') }}</td>
                            <td class="font-mono text-xs">{{ $tx->coupon_number }}</td>
                            <td>{{ $tx->coupon_type === 'QRCODE' ? 'QR' : 'Barras' }}</td>
                            <td class="text-right tabular-nums {{ (float) $tx->transaction_amount < 0 ? 'text-red-700' : '' }}">{{ \App\Support\Format::money($tx->transaction_amount) }}</td>
                            <td>{{ $tx->user?->username ?? '—' }}</td>
                            <td>{{ $tx->location?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-slate-500">Aún no hay cobros. Cuando la app registre el primero, aparecerá aquí.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="subheader mb-3 mt-8">Infraestructura</h2>
    <div class="grid grid-cols-2 gap-6 lg:grid-cols-4">
        @foreach ($stats as $stat)
            <div class="snow-card p-5">
                <div class="subheader">{{ $stat['label'] }}</div>
                <div class="stat-value">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>
</x-admin.layouts.app>
