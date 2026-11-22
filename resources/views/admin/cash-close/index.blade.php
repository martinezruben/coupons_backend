<x-admin.layouts.app title="Cierre de caja">
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">
                Elige la localidad y el turno. El turno empieza donde terminó el último cierre (o a las 00:00 de hoy) y llega hasta ahora.
                Neto = cobrado menos reversas; una reversa cuenta en el periodo en que se hizo y se atribuye al cajero del cobro. Las recargas van aparte.
            </p>
        </div>
        @if(session('status'))<p class="border-b border-green-100 bg-green-50 px-5 py-2 text-sm text-green-800">{{ session('status') }}</p>@endif
        @if($errors->any())<p class="border-b border-red-100 bg-red-50 px-5 py-2 text-sm text-red-700">{{ $errors->first() }}</p>@endif

        <form method="GET" action="{{ route('admin.cash-close.index') }}" class="grid grid-cols-2 items-end gap-2 border-b border-slate-200 p-3 sm:grid-cols-4">
            <div class="col-span-2">
                <label class="snow-label" for="location_id">Localidad</label>
                <select id="location_id" name="location_id" class="snow-input" required>
                    <option value="">Elige una localidad…</option>
                    @foreach($locations as $l)<option value="{{ $l->id }}" @selected($location?->id === $l->id)>{{ $l->name }}</option>@endforeach
                </select>
            </div>
            <div><label class="snow-label" for="from">Desde</label><input id="from" name="from" type="datetime-local" value="{{ $from }}" class="snow-input"></div>
            <div><label class="snow-label" for="to">Hasta</label><input id="to" name="to" type="datetime-local" value="{{ $to }}" class="snow-input"></div>
            <div class="col-span-full flex gap-2">
                <button type="submit" class="snow-btn-primary">Ver turno</button>
                @if($exportUrl && $canExport)<a href="{{ $exportUrl }}" class="snow-btn-secondary">CSV</a>@endif
            </div>
        </form>

        @if($location && $summary)
            @include('admin.cash-close._summary', ['summary' => $summary])

            @if($canClose)
                <form method="POST" action="{{ route('admin.cash-close.close') }}" class="flex flex-wrap items-end gap-2 border-t border-slate-200 bg-slate-50 p-4"
                      onsubmit="return confirm('¿Cerrar la caja de {{ addslashes($location->name) }}? El cierre no se puede editar ni borrar.');">
                    @csrf
                    <input type="hidden" name="location_id" value="{{ $location->id }}">
                    <input type="hidden" name="from" value="{{ $from }}">
                    <input type="hidden" name="to" value="{{ $to }}">
                    <div class="min-w-[220px] flex-1">
                        <label class="snow-label" for="note">Nota (opcional)</label>
                        <input id="note" name="note" type="text" maxlength="255" class="snow-input" value="{{ old('note') }}" placeholder="Ej.: turno de la mañana, diferencias, etc.">
                    </div>
                    <button type="submit" class="snow-btn-primary">Cerrar caja de este turno</button>
                </form>
            @endif
        @elseif($location)
            <p class="px-5 py-8 text-center text-sm text-slate-500">La hora final debe ser posterior a la inicial.</p>
        @else
            <p class="px-5 py-10 text-center text-sm text-slate-500">Elige una localidad para ver su turno.</p>
        @endif
    </div>

    <div class="snow-card mt-4 overflow-hidden">
        <header class="border-b border-slate-200 px-5 py-3"><h2 class="text-base font-medium text-slate-800">Cierres anteriores{{ $location ? ' de '.$location->name : '' }}</h2></header>
        <div class="overflow-x-auto">
            <table class="min-w-[760px]">
                <thead><tr><th class="text-left">Localidad</th><th class="text-left">Periodo</th><th class="text-right">Cobros</th><th class="text-right">Neto cobrado</th><th class="text-left">Cerrado por</th><th class="w-20"></th></tr></thead>
                <tbody>
                @forelse($closings as $c)
                    <tr>
                        <td class="px-3 py-2">{{ $c->location?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-xs tabular-nums">{{ $c->period_from->format('d/m/Y H:i') }} → {{ $c->period_to->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ number_format($c->redemptions) }}</td>
                        <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ \App\Support\Format::money($c->net) }}</td>
                        <td class="px-3 py-2 text-slate-600">{{ $c->closedBy?->name ?? '—' }} · {{ $c->created_at->format('d/m H:i') }}</td>
                        <td class="px-3 py-2 text-right"><a href="{{ route('admin.cash-close.show', $c) }}" class="text-primary-600 hover:underline">Ver</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Aún no hay cierres.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layouts.app>
