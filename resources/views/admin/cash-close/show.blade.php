<x-admin.layouts.app :title="'Cierre de caja #'.$closing->id">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2 print:hidden">
        <a href="{{ route('admin.cash-close.index', ['location_id' => $closing->location_id]) }}" class="text-sm text-primary-600 hover:underline">← Volver a cierres</a>
        <div class="flex gap-2">
            @if($canExport)<a href="{{ route('admin.cash-close.show', [$closing, 'export' => 'csv']) }}" class="snow-btn-secondary">CSV</a>@endif
            <button type="button" class="snow-btn-secondary" onclick="window.print()">Imprimir</button>
        </div>
    </div>
    @if(session('status'))<p class="mb-3 rounded border border-green-100 bg-green-50 px-4 py-2 text-sm text-green-800 print:hidden">{{ session('status') }}</p>@endif

    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-xl font-semibold text-slate-900">{{ $closing->location?->name ?? 'Localidad' }} · cierre #{{ $closing->id }}</h2>
            <p class="mt-1 text-sm text-slate-600">
                Periodo: <strong>{{ $closing->period_from->format('d/m/Y H:i') }}</strong> a <strong>{{ $closing->period_to->format('d/m/Y H:i') }}</strong>
                · cerrado por {{ $closing->closedBy?->name ?? '—' }} el {{ $closing->created_at->format('d/m/Y H:i') }}
            </p>
            @if($closing->note)<p class="mt-1 text-sm text-slate-700">Nota: {{ $closing->note }}</p>@endif
        </div>
        @include('admin.cash-close._summary', ['summary' => $summary])
        <p class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">Cierre inmutable: es la foto de los movimientos al momento de cerrar.</p>
    </div>
</x-admin.layouts.app>
