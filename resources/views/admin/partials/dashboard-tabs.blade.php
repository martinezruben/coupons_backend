{{-- Barra de los dashboards: pestañas comercial / técnico y filtro de localidad --}}
@php
    $admin = auth('admin')->user();
    $locationQuery = $selectedLocation ? ['location_id' => $selectedLocation->id] : [];
    $tabs = collect([
        ['route' => 'admin.dashboard', 'label' => 'Comercial', 'screen' => 'dashboard'],
        ['route' => 'admin.dashboard.technical', 'label' => 'Técnico', 'screen' => 'dashboard-technical'],
    ])->filter(fn ($t) => $admin?->can(\App\Support\AdminRbac::permissionsForScreen($t['screen'])['view']));
@endphp
<div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    @if ($tabs->count() > 1)
        <nav class="flex gap-1 rounded-lg border border-slate-200/90 bg-white p-1 shadow-sm sm:inline-flex" aria-label="Dashboards">
            @foreach ($tabs as $tab)
                @php($active = request()->routeIs($tab['route']))
                <a
                    href="{{ route($tab['route'], $locationQuery) }}"
                    class="flex-1 rounded-md px-3 py-1.5 text-center text-[11px] font-semibold transition sm:flex-none
                        {{ $active ? 'bg-primary-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
                    @if ($active) aria-current="page" @endif
                >{{ $tab['label'] }}</a>
            @endforeach
        </nav>
    @else
        <span></span>
    @endif

    <form method="GET" action="{{ url()->current() }}" class="flex items-center gap-2">
        @isset($generatedAt)
            {{-- Las cifras se reutilizan hasta 60 s (DashboardController::CACHE_SECONDS) --}}
            <span class="hidden shrink-0 text-[11px] text-slate-500 md:inline" title="Las cifras se recalculan cada minuto">Actualizado {{ $generatedAt }}</span>
        @endisset
        <label for="dash-location" class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Localidad</label>
        <select
            id="dash-location"
            name="location_id"
            class="hope-filter-select min-w-0 flex-1 text-[11px] sm:w-56 sm:flex-none"
            onchange="this.form.submit()"
        >
            <option value="">Todas las localidades</option>
            @foreach ($locationOptions as $opt)
                <option value="{{ $opt->id }}" @selected($selectedLocation?->id === $opt->id)>
                    {{ $opt->name }}{{ $opt->is_active ? '' : ' (inactiva)' }}
                </option>
            @endforeach
        </select>
        <noscript><button type="submit" class="rounded-md border border-slate-300 px-2 py-1 text-[11px]">Filtrar</button></noscript>
    </form>
</div>
