@php
    $badgeFields = ['status', 'is_active', 'is_enabled', 'is_favorite', 'is_synced', 'role', 'payment_method', 'operation', 'response_status'];
    $visibleLimit = (int) ($cfg['grid']['visible_limit'] ?? 8);
    $gridExclude = $cfg['grid']['exclude_from_grid'] ?? [];
    $gridFieldList = array_values(array_filter($cfg['fields'] ?? [], static fn (string $f): bool => ! in_array($f, $gridExclude, true)));
    if (! empty($cfg['grid']['field_order'])) {
        $fieldPos = array_flip($cfg['grid']['field_order']);
        usort($gridFieldList, static fn (string $a, string $b): int => ($fieldPos[$a] ?? 999) <=> ($fieldPos[$b] ?? 999));
    }
    $visibleFields = array_slice($gridFieldList, 0, max(1, $visibleLimit));
    $gridFilterOptions = $gridFilterOptions ?? [];
    $gridFilters = $cfg['grid']['filters'] ?? [];
    $sortable = $cfg['grid']['sortable'] ?? [];
    $locationPairing = $screen === 'locations' && ($canEdit ?? false);
    $apiLogDetail = $screen === 'api-request-logs';
    $auditLogDetail = $screen === 'audit-log';
    $transactionLineItems = $screen === 'transactions';
    $productExcel = $screen === 'products' && ($canEdit ?? false);
@endphp

<x-admin.layouts.app :title="$cfg['label']">
@if($locationPairing)
<div x-data="locationPairingToken()" @open-location-pairing.window="openFor($event.detail)">
@elseif($apiLogDetail)
<div x-data="apiRequestLogDetail(@js(url('/admin/api-request-logs')))">
@elseif($auditLogDetail)
<div x-data="auditLogDetail(@js(url('/admin/audit-logs')))">
@elseif($transactionLineItems)
<div x-data="transactionLineItemsDetail(@js(url('/admin/transactions')))">
@endif
    <div class="snow-card overflow-hidden">
        <form method="GET" action="{{ route('admin.screens.index', $screen) }}" class="border-b border-slate-100">
            <div class="flex flex-col gap-1.5 bg-white px-2 py-1.5 sm:flex-row sm:items-center sm:justify-between sm:px-3">
                <div class="flex flex-wrap items-center gap-0.5">
                    @if(($showTransactionExcelExport ?? false))
                        <div x-data="transactionExcelModal(@js($transactionExportLocations ?? []))" class="inline-flex items-center gap-0.5">
                            <button
                                type="button"
                                class="inline-flex h-7 items-center gap-0.5 rounded-md border border-slate-200 bg-white px-1.5 text-[10px] font-semibold text-slate-600 shadow-sm transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700"
                                title="Exportar transacciones a Excel"
                                @click="open = true"
                            >
                                <svg class="h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                Excel
                            </button>
                            @include('admin.crud.partials.transaction-excel-export-modal')
                        </div>
                    @endif
                    @if(($showTransactionReportExport ?? false))
                        <div x-data="transactionsReportModal(@js($transactionExportLocations ?? []))" class="inline-flex items-center gap-0.5">
                            <button
                                type="button"
                                class="inline-flex h-7 items-center gap-0.5 rounded-md border border-slate-200 bg-white px-1.5 text-[10px] font-semibold text-slate-600 shadow-sm transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700"
                                title="Descargar reporte de transacciones (opcional: detalle de líneas)"
                                @click="open = true"
                            >
                                <svg class="h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                Descargar
                            </button>
                            @include('admin.crud.partials.transactions-report-export-modal')
                        </div>
                    @endif
                    @if(($canEdit ?? false) && empty($cfg['disable_create'] ?? false))
                        <a
                            href="{{ route('admin.screens.create', $screen) }}"
                            class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-primary-600 text-white shadow-sm transition hover:bg-primary-700"
                            title="Añadir"
                        >
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        </a>
                        @if($productExcel ?? false)
                            <a
                                href="{{ route('admin.products.excel.export') }}"
                                class="inline-flex h-7 items-center gap-0.5 rounded-md border border-slate-200 bg-white px-1.5 text-[10px] font-semibold text-slate-600 shadow-sm transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700"
                                title="Exportar todos los productos a Excel"
                            >
                                <svg class="h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                Excel
                            </a>
                            <form method="POST" action="{{ route('admin.products.excel.import') }}" enctype="multipart/form-data" class="inline-flex items-center">
                                @csrf
                                <label class="inline-flex h-7 cursor-pointer items-center gap-0.5 rounded-md border border-slate-200 bg-white px-1.5 text-[10px] font-semibold text-slate-600 shadow-sm transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700" title="Importar Excel (actualiza o crea por product_id)">
                                    <svg class="h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" /></svg>
                                    Importar
                                    <input type="file" name="file" accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" class="sr-only" onchange="this.form.submit()">
                                </label>
                            </form>
                        @endif
                    @endif
                </div>
                <div class="relative flex w-full min-w-0 flex-1 sm:max-w-[220px] md:max-w-sm">
                    <span class="pointer-events-none absolute inset-y-0 left-1.5 flex items-center text-slate-500">
                        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                    </span>
                    <input
                        type="search"
                        name="q"
                        value="{{ request('q') }}"
                        placeholder="Buscar…"
                        class="hope-grid-search"
                    >
                </div>
                <input type="hidden" name="sort" value="{{ request('sort') }}">
                <input type="hidden" name="dir" value="{{ request('dir') }}">
            </div>

            @if($gridFilters !== [])
                <div class="flex flex-wrap items-end gap-1.5 border-t border-slate-50 bg-slate-50/70 px-2 py-3 sm:px-3 sm:py-3.5" x-data="selectCascade">
                    @foreach($gridFilters as $param => $fdef)
                        <div class="flex min-w-[7rem] flex-col gap-0.5">
                            <label class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500" for="flt-{{ $param }}">{{ $fdef['label'] ?? $param }}</label>
                            @if(($fdef['type'] ?? '') === 'date')
                                <input
                                    id="flt-{{ $param }}"
                                    type="date"
                                    name="filter[{{ $param }}]"
                                    value="{{ request('filter.'.$param) }}"
                                    class="hope-filter-select"
                                >
                            @else
                                <select id="flt-{{ $param }}" name="filter[{{ $param }}]" class="hope-filter-select" @if(!empty($fdef['depends'])) data-depends="{{ $fdef['depends'] }}" @endif>
                                    @foreach(($gridFilterOptions[$param] ?? collect()) as $opt)
                                        <option value="{{ $opt['id'] }}" @selected((string) request('filter.'.$param) === (string) $opt['id']) @if(isset($opt['parent'])) data-parent="{{ $opt['parent'] }}" @endif>{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    @endforeach
                    <button type="submit" class="rounded-md bg-primary-600 px-2 py-1 text-[10px] font-semibold text-white shadow-sm transition hover:bg-primary-700">Aplicar</button>
                    <a href="{{ route('admin.screens.index', $screen) }}" class="rounded-md py-1 text-[10px] font-medium text-slate-500 hover:text-primary-600">Limpiar</a>
                </div>
            @endif
        </form>

        @if(($screen ?? null) === 'locations')
            <script type="application/json" id="admin-locations-map-data">@json($locationsMapPins ?? [])</script>
            <div class="border-b border-slate-100 bg-gradient-to-b from-slate-50/90 to-white px-2 py-2 sm:px-3">
                <div class="mb-1.5 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h3 class="text-[11px] font-mono font-bold uppercase tracking-wider text-slate-500">Mapa de localidades</h3>
                        <p class="text-[11px] text-slate-500">Mismos filtros que la tabla · el número es la cantidad de dispositivos activos · el estado se actualiza cada minuto</p>
                    </div>
                    <div class="flex min-w-0 flex-col gap-0.5 sm:items-end">
                        <label for="admin-locations-map-period" class="text-[11px] font-mono font-bold uppercase tracking-wider text-slate-500">Sync exitosa en</label>
                        <select id="admin-locations-map-period" class="hope-filter-select max-w-full py-1 text-[11px] sm:min-w-[12rem]">
                            <option value="today" selected>Hoy</option>
                            <option value="yesterday">Ayer</option>
                            <option value="last_week">Última semana</option>
                            <option value="last_month">Último mes</option>
                        </select>
                    </div>
                </div>
                @php
                    $mapStatuses = [
                        'online' => ['label' => 'En línea', 'glyph' => '✓', 'dot' => 'bg-green-600'],
                        'stale' => ['label' => 'Sin sincronizar', 'glyph' => '!', 'dot' => 'bg-amber-600'],
                        'contingency' => ['label' => 'En contingencia', 'glyph' => '▲', 'dot' => 'bg-red-600'],
                        'inactive' => ['label' => 'Inactiva', 'glyph' => '–', 'dot' => 'bg-slate-400'],
                    ];
                @endphp
                <div class="mb-2 flex flex-wrap items-center gap-1.5" role="group" aria-label="Filtrar el mapa por estado">
                    @foreach ($mapStatuses as $key => $st)
                        <button
                            type="button"
                            data-map-filter="{{ $key }}"
                            aria-pressed="true"
                            class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2 py-1 text-[11px] font-medium text-slate-700 shadow-sm transition hover:border-primary-300"
                        >
                            <span class="flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold text-white {{ $st['dot'] }}" aria-hidden="true">{{ $st['glyph'] }}</span>
                            {{ $st['label'] }}
                            <span data-map-count="{{ $key }}" class="font-mono tabular-nums text-slate-500">{{ collect($locationsMapPins ?? [])->where('status', $key)->count() }}</span>
                        </button>
                    @endforeach
                </div>
                <div
                    id="admin-locations-map"
                    data-status-url="{{ route('admin.locations.map-status') }}"
                    class="z-0 h-[min(420px,52vh)] w-full overflow-hidden rounded-lg border border-slate-200/90 shadow-sm ring-1 ring-slate-200/40"
                    role="region"
                    aria-label="Mapa de localidades"
                ></div>
                @php
                    $hasCoords = collect($locationsMapPins ?? [])->contains(
                        fn (array $p) => ($p['lat'] ?? null) !== null && ($p['lng'] ?? null) !== null,
                    );
                @endphp
                @if (count($locationsMapPins ?? []) > 0)
                    <details class="mt-2 text-[11px] text-slate-700">
                        <summary class="cursor-pointer font-semibold text-slate-600">Ver estado en lista</summary>
                        <ul id="admin-locations-map-list" class="mt-1.5 divide-y divide-slate-100 rounded-md border border-slate-200 bg-white">
                            @foreach ($locationsMapPins as $pin)
                                <li data-loc-id="{{ $pin['id'] }}" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 px-2.5 py-1.5">
                                    <span class="font-medium text-slate-800">{{ $pin['name'] }}</span>
                                    <span class="flex items-center gap-3 text-slate-500">
                                        <span data-loc-status-label class="font-semibold">{{ $mapStatuses[$pin['status']]['glyph'] }} {{ $pin['statusLabel'] }}</span>
                                        <span>Última sync: <span data-loc-last-sync>{{ $pin['lastSyncHuman'] ?? 'nunca' }}</span></span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
                @if (! $hasCoords && count($locationsMapPins ?? []) > 0)
                    <p class="mt-1.5 text-center text-[11px] text-amber-800">
                        Indica latitud y longitud en cada localidad para mostrarla en el mapa.
                    </p>
                @endif
            </div>
            @push('scripts')
                @vite(['resources/js/admin-locations-map.js'])
            @endpush
        @endif

        @error('file')
            <p class="border-b border-red-100 bg-red-50/80 px-3 py-2 text-xs text-red-700">{{ $message }}</p>
        @enderror

        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] border-collapse text-left text-[10px] leading-tight">
                <thead class="sticky top-0 z-10 border-b border-slate-100 bg-slate-50/95 backdrop-blur-sm">
                <tr class="snow-table-head">
                    @foreach($visibleFields as $f)
                        <th class="whitespace-nowrap px-2 py-1 font-semibold">
                            @if(in_array($f, $sortable, true))
                                <a href="{{ \App\Support\AdminGridQuery::sortUrl($screen, $f, request('sort'), request('dir')) }}" class="inline-flex items-center gap-1 text-inherit hover:text-primary-600">
                                    <span>{{ \App\Support\AdminGridCell::headerLabel($f, $cfg) }}</span>
                                    @if(request('sort') === $f)
                                        <span class="font-normal text-primary-600" aria-hidden="true">{{ request('dir') === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </a>
                            @else
                                {{ \App\Support\AdminGridCell::headerLabel($f, $cfg) }}
                            @endif
                        </th>
                    @endforeach
                    <th class="w-px whitespace-nowrap px-2 py-1 text-right">{{ __('Acciones') }}</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                @foreach($items as $item)
                    <tr class="transition-colors hover:bg-slate-50/90">
                        @foreach($visibleFields as $f)
                            <td class="max-w-[12rem] px-2 py-1 align-middle text-slate-800">
                                @if($f === 'image_url' && ! empty($item->image_url))
                                                                <img src="{{ \App\Services\ImageThumbnailService::displayUrl($item->image_url) }}" alt="" class="h-7 w-7 shrink-0 rounded object-cover ring-1 ring-slate-200" width="28" height="28">
                                                            @elseif($f === 'image_url')
                                                                <span class="inline-flex h-7 w-7 items-center justify-center rounded bg-slate-100/80 ring-1 ring-slate-200/80" title="Sin imagen" aria-label="Sin imagen">
                                                                    <svg class="h-3.5 w-3.5 text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5v9l9 5.25M3 7.5l9 5.25m0-9v9"/></svg>
                                                                </span>
                                @elseif($f === 'pin_status' && $item instanceof \App\Models\User)
                                    @if($item->hasOfflinePin())
                                        <span class="snow-tag bg-emerald-50 text-emerald-800">✓ Listo</span>
                                    @else
                                        <span class="snow-tag bg-amber-50 text-amber-800" title="Su PIN no funciona sin conexión hasta que se guarde de nuevo su contraseña">! Restablecer contraseña</span>
                                    @endif
                                @elseif(in_array($f, $badgeFields, true) && empty($cfg['foreign_labels'][$f]))
                                    <x-admin.snow.badge :field="$f" :value="$item->{$f}" />
                                @else
                                    <span class="block truncate font-normal text-slate-600">{{ \App\Support\AdminGridCell::display($item, $f, $cfg) }}</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="whitespace-nowrap px-2 py-1 text-right align-middle">
                            <div class="inline-flex items-center gap-0">
                                @if($auditLogDetail)
                                    <button
                                        type="button"
                                        class="snow-icon-btn h-6 w-6"
                                        title="Ver detalle"
                                        @click="openDetail('{{ $item->getKey() }}')"
                                    >
                                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                    </button>
                                @endif
                                @if($apiLogDetail)
                                    <button
                                        type="button"
                                        class="snow-icon-btn h-6 w-6"
                                        title="Ver detalle"
                                        @click="openDetail('{{ $item->getKey() }}')"
                                    >
                                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                    </button>
                                @endif
                                @if($transactionLineItems)
                                    <button
                                        type="button"
                                        class="snow-icon-btn h-6 w-6"
                                        title="Ver líneas y pagos"
                                        @click="openDetail('{{ $item->getKey() }}')"
                                    >
                                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                    </button>
                                @endif
                                @if(($locationPairing ?? false))
                                    <button
                                        type="button"
                                        class="snow-icon-btn h-6 w-6"
                                        title="Código temporal de vinculación"
                                        @click="$dispatch('open-location-pairing', { locationId: '{{ $item->getKey() }}', locationName: @js($item->name) })"
                                    >
                                        <x-admin.snow.icon name="key" class="h-3.5 w-3.5" />
                                    </button>
                                @endif
                                @if(($canEdit ?? false) && in_array($screen, ['licenses'], true))
                                    <form method="POST" action="{{ route('admin.screens.toggle-status', [$screen, $item->getKey()]) }}" class="inline" onsubmit="return confirm('¿Cambiar estado de esta licencia?');">
                                        @csrf
                                        <button type="submit"
                                            class="snow-icon-btn h-6 w-6"
                                            title="Alternar estado (ACTIVE ↔ INACTIVE)">
                                            <svg class="h-3.5 w-3.5 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 6h10M7 12h10M7 18h10" />
                                            </svg>
                                            <span class="sr-only">Alternar estado</span>
                                        </button>
                                    </form>
                                @endif
                                @if($canEdit ?? false)
                                    <a
                                        href="{{ route('admin.screens.edit', [$screen, $item->getKey()]) }}"
                                        class="snow-icon-btn h-6 w-6"
                                        title="Editar"
                                    >
                                        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                    </a>
                                @endif
                                @if($canDelete ?? false)
                                    <form method="POST" action="{{ route('admin.screens.destroy', [$screen, $item->getKey()]) }}" class="inline" onsubmit="return confirm('¿Eliminar este registro?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="snow-icon-btn-danger h-6 w-6" title="Eliminar">
                                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex justify-end border-t border-slate-100 bg-slate-50/50 px-2 py-1">
            {{ $items->links('vendor.pagination.admin-grid') }}
        </div>
    </div>
@if($locationPairing)
    @include('admin.crud.partials.location-pairing-modal')
</div>
@elseif($apiLogDetail)
    @include('admin.crud.partials.api-request-log-detail-modal')
@include('admin.crud.partials.audit-log-detail-modal')
</div>
@elseif($transactionLineItems)
    @include('admin.crud.partials.transaction-line-items-modal')
</div>
@endif
</x-admin.layouts.app>
