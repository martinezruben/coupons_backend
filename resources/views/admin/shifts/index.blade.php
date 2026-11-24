<x-admin.layouts.app title="Turnos">
    @php $money = fn ($v) => \App\Support\Format::money($v); @endphp
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">
                Cada localidad tiene siempre un turno abierto. Al cerrarlo eliges la hora de corte: lo anterior queda en ese turno y lo posterior pasa al siguiente.
                @if($narrowed) Se muestran las localidades de tu selección actual. @endif
            </p>
        </div>
        @if(session('status'))<p class="border-b border-green-100 bg-green-50 px-5 py-2 text-sm text-green-800">{{ session('status') }}</p>@endif

        <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($cards as $c)
                <a href="{{ route('admin.shifts.location', $c['location']->id) }}" class="block rounded-lg border border-slate-200 p-4 transition hover:border-primary-500 hover:shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="font-semibold text-slate-900">{{ $c['location']->name }}</div>
                        <span class="snow-tag bg-green-50 text-green-800">Turno {{ $c['shift']->number }} abierto</span>
                    </div>
                    <div class="mt-2 text-xs text-slate-500">Desde {{ $c['shift']->starts_at->format('d/m/Y H:i') }}</div>
                    <div class="mt-3 flex items-end justify-between">
                        <div><div class="text-[10px] font-bold uppercase tracking-[0.12em] text-slate-500">Neto cobrado</div><div class="text-xl font-semibold tabular-nums">{{ $money($c['totals']['net']) }}</div></div>
                        <div class="text-right text-xs text-slate-600">{{ number_format($c['totals']['redemptions']) }} cobros</div>
                    </div>
                    <div class="mt-3 text-xs font-semibold text-primary-600">Ver turno y cerrar →</div>
                </a>
            @empty
                <p class="col-span-full px-2 py-8 text-center text-sm text-slate-500">No hay localidades en tu alcance.</p>
            @endforelse
        </div>
    </div>

    <div class="snow-card mt-4 overflow-hidden">
        <header class="border-b border-slate-200 px-5 py-3"><h2 class="text-base font-medium text-slate-800">Turnos cerrados</h2></header>
        <div class="overflow-x-auto">
            <table class="min-w-[820px]">
                <thead><tr><th class="text-left">Localidad</th><th class="text-left">Turno</th><th class="text-left">Periodo</th><th class="text-right">Cobros</th><th class="text-right">Neto cobrado</th><th class="text-left">Cerrado por</th><th class="w-16"></th></tr></thead>
                <tbody>
                @forelse($history as $s)
                    <tr>
                        <td class="px-3 py-2">{{ $s->location?->name ?? '—' }}</td>
                        <td class="px-3 py-2 font-semibold">#{{ $s->number }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-xs tabular-nums">{{ $s->starts_at->format('d/m/Y H:i') }} → {{ $s->ends_at->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($s->redemptions) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $money($s->net) }}</td>
                        <td class="px-3 py-2 text-slate-600">{{ $s->closedBy?->name ?? '—' }} · {{ $s->closed_at?->format('d/m H:i') }}</td>
                        <td class="px-3 py-2 text-right"><a href="{{ route('admin.shifts.show', $s) }}" class="text-primary-600 hover:underline">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Aún no hay turnos cerrados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-3">{{ $history->links() }}</div>
    </div>
</x-admin.layouts.app>
