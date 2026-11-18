<x-admin.layouts.app title="Alertas de seguridad">
    @php $tag = ['high' => 'bg-red-50 text-red-800', 'medium' => 'bg-amber-50 text-amber-800', 'low' => 'bg-slate-100 text-slate-700']; $sev = ['high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja']; @endphp
    <div class="snow-card">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">Cobros bloqueados por límite, cupones usados en dos localidades, reversas repetidas, consultas fallidas y ajustes pendientes. Las de severidad alta también llegan por correo a los super-administradores.</p>
        </div>
        @if(session('status'))<p class="border-b border-green-100 bg-green-50 px-5 py-2 text-sm text-green-800">{{ session('status') }}</p>@endif
        <form method="GET" class="flex flex-wrap items-end gap-2 border-b border-slate-200 p-3">
            <div><label class="snow-label" for="show">Mostrar</label><select id="show" name="show" class="snow-input"><option value="open" @selected($show === 'open')>Sin revisar</option><option value="all" @selected($show === 'all')>Todas</option></select></div>
            <div><label class="snow-label" for="severity">Severidad</label><select id="severity" name="severity" class="snow-input"><option value="">Todas</option>@foreach($sev as $k => $l)<option value="{{ $k }}" @selected($severity === $k)>{{ $l }}</option>@endforeach</select></div>
            <button class="snow-btn-primary">Filtrar</button>
        </form>
        <div class="overflow-x-auto">
            <table class="min-w-[800px]">
                <thead><tr><th class="text-left">Fecha</th><th class="text-left">Severidad</th><th class="text-left">Alerta</th><th class="text-left">Cupón</th><th class="w-32"></th></tr></thead>
                <tbody>
                @forelse($alerts as $a)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $a->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2"><span class="snow-tag {{ $tag[$a->severity] ?? '' }}">{{ $sev[$a->severity] ?? $a->severity }}</span></td>
                        <td class="px-3 py-2 text-slate-700">{{ $a->message }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $a->coupon_number ?? '—' }}</td>
                        <td class="px-3 py-2 text-right">
                            @if($a->resolved_at)<span class="text-xs text-slate-500">Revisada</span>
                            @elseif($canResolve)<form method="POST" action="{{ route('admin.security-alerts.resolve', $a->id) }}">@csrf<button class="snow-btn-secondary snow-btn-sm">Marcar revisada</button></form>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No hay alertas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-3">{{ $alerts->links() }}</div>
    </div>
</x-admin.layouts.app>
