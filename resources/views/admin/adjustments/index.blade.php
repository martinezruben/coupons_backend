<x-admin.layouts.app title="Ajustes de saldo">
    @php $tabs = ['PENDING' => 'Pendientes', 'APPROVED' => 'Aprobados', 'REJECTED' => 'Rechazados']; @endphp
    <div class="snow-card">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">Fijar el saldo de un cupón necesita dos personas: quien lo pide y otro administrador que lo aprueba. No puedes aprobar tus propias solicitudes.</p>
        </div>
        @if(session('status'))<p class="border-b border-green-100 bg-green-50 px-5 py-2 text-sm text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<p class="border-b border-red-100 bg-red-50 px-5 py-2 text-sm text-red-700">{{ $errors->first() }}</p>@endif
        <div class="flex gap-2 border-b border-slate-200 p-3">
            @foreach($tabs as $k => $l)<a href="{{ route('admin.adjustments.index', ['status' => $k]) }}" class="{{ $status === $k ? 'snow-btn-primary' : 'snow-btn-secondary' }}">{{ $l }}</a>@endforeach
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[900px]">
                <thead><tr><th class="text-left">Fecha</th><th class="text-left">Cupón</th><th class="text-right">Saldo actual</th><th class="text-right">Saldo pedido</th><th class="text-left">Motivo</th><th class="text-left">Pedido por</th><th class="w-56"></th></tr></thead>
                <tbody>
                @forelse($rows as $r)
                    @php $own = $r->requested_by_admin_id !== null && (int) $r->requested_by_admin_id === (int) $me; @endphp
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $r->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $r->coupon_number }} <span class="text-slate-400">{{ $r->coupon_type === 'QRCODE' ? 'QR' : 'Barras' }}</span></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ \App\Support\Format::money($r->current_balance) }}</td>
                        <td class="px-3 py-2 text-right font-medium tabular-nums">{{ \App\Support\Format::money($r->new_balance) }}</td>
                        <td class="px-3 py-2 text-slate-700">{{ $r->reason }}</td>
                        <td class="px-3 py-2 text-slate-700">{{ $r->requested_by_admin_id ? ($adminNames[$r->requested_by_admin_id] ?? 'Admin') : ($userNames[$r->requested_by_user_id] ?? 'App') }}</td>
                        <td class="px-3 py-2 text-right">
                            @if($r->status === 'PENDING' && $canApprove)
                                @if($own)<span class="text-xs text-slate-500">Lo pediste tú: lo aprueba otra persona</span>
                                @else
                                    <form method="POST" action="{{ route('admin.adjustments.approve', $r->id) }}" class="inline">@csrf<button class="snow-btn-primary snow-btn-sm" onclick="return confirm('¿Aplicar este ajuste de saldo?')">Aprobar</button></form>
                                    <form method="POST" action="{{ route('admin.adjustments.reject', $r->id) }}" class="inline">@csrf<button class="snow-btn-secondary snow-btn-sm">Rechazar</button></form>
                                @endif
                            @elseif($r->status !== 'PENDING')
                                <span class="text-xs text-slate-500">{{ $r->status === 'APPROVED' ? 'Aprobado' : 'Rechazado' }} por {{ $adminNames[$r->decided_by_admin_id] ?? '—' }} · {{ $r->decided_at?->format('d/m/Y H:i') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No hay solicitudes.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-3">{{ $rows->links() }}</div>
    </div>
</x-admin.layouts.app>
