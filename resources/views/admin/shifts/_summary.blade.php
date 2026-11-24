{{-- Resumen de un turno: totales, por cajero y por surtidor. Recibe $summary. --}}
@php $money = fn ($v) => \App\Support\Format::money($v); $t = $summary['totals']; @endphp
<div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-5">
    @foreach([['Cobros', number_format($t['redemptions'])], ['Cobrado', $money($t['redeemed'])], ['Reversado', $money($t['reversed'])], ['Neto cobrado', $money($t['net'])], ['Recargado', $money($t['topups'])]] as [$label, $value])
        <div class="rounded-md border border-slate-200 p-3 {{ $label === 'Neto cobrado' ? 'bg-slate-900 text-white' : '' }}">
            <div class="text-[10px] font-bold uppercase tracking-[0.12em] {{ $label === 'Neto cobrado' ? 'text-slate-300' : 'text-slate-500' }}">{{ $label }}</div>
            <div class="mt-1 text-lg font-semibold tabular-nums">{{ $value }}</div>
        </div>
    @endforeach
</div>

<div class="overflow-x-auto">
    <table class="min-w-[760px]">
        <thead><tr><th class="text-left">Cajero</th><th class="text-right">Cobros</th><th class="text-right">Cobrado</th><th class="text-right">Reversado</th><th class="text-right">Neto</th><th class="text-right">Recargado</th><th class="text-left">Primer / último movimiento</th></tr></thead>
        <tbody>
        @forelse($summary['cashiers'] as $c)
            <tr>
                <td class="px-3 py-2 font-medium text-slate-800">{{ $c['name'] }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($c['redemptions']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $money($c['redeemed']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-amber-800">{{ $money($c['reversed']) }}</td>
                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $money($c['net']) }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $money($c['topups']) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">{{ $c['first_at'] ? \Carbon\Carbon::parse($c['first_at'])->format('H:i') : '—' }} – {{ $c['last_at'] ? \Carbon\Carbon::parse($c['last_at'])->format('H:i') : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No hubo movimientos en este periodo.</td></tr>
        @endforelse
        </tbody>
        @if(count($summary['cashiers']) > 1)
            <tfoot><tr class="border-t-2 border-slate-300 font-semibold"><td class="px-3 py-2">Total</td><td class="px-3 py-2 text-right tabular-nums">{{ number_format($t['redemptions']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($t['redeemed']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($t['reversed']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($t['net']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($t['topups']) }}</td><td></td></tr></tfoot>
        @endif
    </table>
</div>

@if(count($summary['dispensers']) > 0 && collect($summary['dispensers'])->contains(fn ($d) => $d['dispenser'] !== null))
    <div class="border-t border-slate-200 p-4">
        <h3 class="mb-2 text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Por surtidor</h3>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
            @foreach($summary['dispensers'] as $d)
                <div class="rounded-md border border-slate-200 p-2.5">
                    <div class="text-xs font-semibold text-slate-600">{{ $d['dispenser'] === null ? 'Sin surtidor' : 'Surtidor '.$d['dispenser'] }}</div>
                    <div class="mt-0.5 text-base font-semibold tabular-nums">{{ $money($d['net']) }}</div>
                    <div class="text-[11px] text-slate-500">{{ number_format($d['redemptions']) }} cobros</div>
                </div>
            @endforeach
        </div>
    </div>
@endif
