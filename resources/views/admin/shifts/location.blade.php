<x-admin.layouts.app :title="'Turno '.$shift->number.' · '.$location->name">
    @php $money = fn ($v) => \App\Support\Format::money($v); @endphp
    <div class="mb-3"><a href="{{ route('admin.shifts.index') }}" class="text-sm text-primary-600 hover:underline">← Volver a turnos</a></div>
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xl font-semibold text-slate-900">{{ $location->name }} · turno {{ $shift->number }}</h2>
                <span class="snow-tag bg-green-50 text-green-800">Abierto</span>
            </div>
            <p class="mt-1 text-sm text-slate-600">Empezó el <strong>{{ $shift->starts_at->format('d/m/Y H:i') }}</strong>. Al cerrarlo, todo lo anterior a la hora de corte que elijas queda en este turno y lo de ahí en adelante pasa al turno {{ $shift->number + 1 }}.</p>
        </div>
        @if($errors->any())<p class="border-b border-red-100 bg-red-50 px-5 py-2 text-sm text-red-700">{{ $errors->first() }}</p>@endif

        <form method="GET" action="{{ route('admin.shifts.location', $location->id) }}" class="flex flex-wrap items-end gap-2 border-b border-slate-200 bg-slate-50 p-3">
            <div>
                <label class="snow-label" for="until">Hora de corte</label>
                <input id="until" name="until" type="datetime-local" value="{{ $until->format('Y-m-d\TH:i') }}" min="{{ $shift->starts_at->format('Y-m-d\TH:i') }}" max="{{ now()->format('Y-m-d\TH:i') }}" class="snow-input">
            </div>
            <button type="submit" class="snow-btn-secondary">Ver qué entra</button>
            <a href="{{ route('admin.shifts.location', $location->id) }}" class="text-xs text-slate-500 hover:underline">Usar la hora actual</a>
        </form>

        @if($valid && $preview)
            <div class="border-b border-slate-200 bg-blue-50/60 px-5 py-2 text-sm text-blue-900">
                <strong>Entra en este turno:</strong> de {{ $preview['from']->format('d/m/Y H:i') }} hasta las {{ $preview['until']->format('d/m/Y H:i') }} (sin incluir esa hora).
                @if($preview['next']['count'] > 0)
                    <br><strong>Queda para el turno {{ $shift->number + 1 }}:</strong> {{ number_format($preview['next']['count']) }} cobros ({{ $money($preview['next']['amount']) }}) de esa hora en adelante.
                @endif
            </div>
            @include('admin.shifts._summary', ['summary' => $preview['summary']])

            @if($canClose)
                <form method="POST" action="{{ route('admin.shifts.close', $location->id) }}" class="flex flex-wrap items-end gap-2 border-t border-slate-200 bg-slate-50 p-4"
                      onsubmit="return confirm('¿Cerrar el turno {{ $shift->number }} de {{ addslashes($location->name) }} a las {{ $until->format('d/m/Y H:i') }}? El cierre no se puede editar ni borrar.');">
                    @csrf
                    <input type="hidden" name="until" value="{{ $until->format('Y-m-d\TH:i') }}">
                    <div class="min-w-[220px] flex-1">
                        <label class="snow-label" for="note">Nota (opcional)</label>
                        <input id="note" name="note" type="text" maxlength="255" class="snow-input" value="{{ old('note') }}" placeholder="Ej.: turno de la mañana, diferencias, etc.">
                    </div>
                    <button type="submit" class="snow-btn-primary">Cerrar turno {{ $shift->number }} a las {{ $until->format('H:i') }}</button>
                </form>
            @endif
        @else
            <p class="px-5 py-8 text-center text-sm text-slate-500">La hora de corte debe ser posterior al inicio del turno ({{ $shift->starts_at->format('d/m/Y H:i') }}) y no puede ser futura.</p>
        @endif
    </div>

    <div class="snow-card mt-4 overflow-hidden">
        <header class="border-b border-slate-200 px-5 py-3"><h2 class="text-base font-medium text-slate-800">Turnos anteriores de {{ $location->name }}</h2></header>
        <div class="overflow-x-auto">
            <table class="min-w-[640px]">
                <thead><tr><th class="text-left">Turno</th><th class="text-left">Periodo</th><th class="text-right">Cobros</th><th class="text-right">Neto cobrado</th><th class="w-16"></th></tr></thead>
                <tbody>
                @forelse($history as $h)
                    <tr>
                        <td class="px-3 py-2 font-semibold">#{{ $h->number }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-xs tabular-nums">{{ $h->starts_at->format('d/m/Y H:i') }} → {{ $h->ends_at->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($h->redemptions) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ $money($h->net) }}</td>
                        <td class="px-3 py-2 text-right"><a href="{{ route('admin.shifts.show', $h) }}" class="text-primary-600 hover:underline">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Aún no hay turnos cerrados en esta localidad.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layouts.app>
