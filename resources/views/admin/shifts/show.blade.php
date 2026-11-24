<x-admin.layouts.app :title="'Turno '.$shift->number.' · '.($shift->location?->name ?? '')">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2 print:hidden">
        <a href="{{ route('admin.shifts.index') }}" class="text-sm text-primary-600 hover:underline">← Volver a turnos</a>
        <div class="flex gap-2">
            @if($canExport)<a href="{{ route('admin.shifts.show', [$shift, 'export' => 'csv']) }}" class="snow-btn-secondary">CSV</a>@endif
            <button type="button" class="snow-btn-secondary" onclick="window.print()">Imprimir</button>
        </div>
    </div>
    @if(session('status'))<p class="mb-3 rounded border border-green-100 bg-green-50 px-4 py-2 text-sm text-green-800 print:hidden">{{ session('status') }}</p>@endif

    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-xl font-semibold text-slate-900">{{ $shift->location?->name ?? 'Localidad' }} · turno {{ $shift->number }}</h2>
                <span class="snow-tag bg-slate-100 text-slate-700">Cerrado</span>
            </div>
            <p class="mt-1 text-sm text-slate-600">
                Periodo: <strong>{{ $shift->starts_at->format('d/m/Y H:i') }}</strong> hasta las <strong>{{ $shift->ends_at->format('d/m/Y H:i') }}</strong> (sin incluir esa hora)
                · cerrado por {{ $shift->closedBy?->name ?? '—' }} el {{ $shift->closed_at?->format('d/m/Y H:i') }}
            </p>
            @if($shift->note)<p class="mt-1 text-sm text-slate-700">Nota: {{ $shift->note }}</p>@endif
        </div>
        @include('admin.shifts._summary', ['summary' => $summary])
        <p class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">Turno inmutable: es la foto de lo que entró al cerrarlo. Los movimientos quedan guardados en este turno.</p>
    </div>
</x-admin.layouts.app>
