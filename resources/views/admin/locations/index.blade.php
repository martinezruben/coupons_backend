@php
    $tone = [
        'online' => ['b-ok', '#FFFFFF', '#E2E6DC'],
        'stale' => ['b-warn', '#FFF7E5', '#8A4B00'],
        'contingency' => ['b-bad', '#FDF0EE', '#A3261B'],
        'inactive' => ['b-mute', '#F1F4EC', '#E2E6DC'],
    ];
    $labels = ['online' => 'En línea', 'stale' => 'Sin sincronizar', 'contingency' => 'En contingencia', 'inactive' => 'Inactiva'];
    $locLabels = \App\Support\LocationMapSyncPins::STATUS_LABELS;
@endphp
<x-admin.layouts.app title="Localidades">
<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-5">
        <p class="max-w-[560px] text-[15px] text-slate-600">Los puntos donde operan tus dispositivos. El estado se calcula con la última sincronización de cada dispositivo.</p>
        @if($canEdit)<a href="{{ route('admin.screens.create', 'locations') }}" class="snow-btn-primary">Nueva localidad</a>@endif
    </div>

    <section class="flex flex-wrap items-stretch gap-6" aria-label="Estado de las localidades">
        <div class="grid flex-[1_1_560px] grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3.5">
            @foreach($labels as $key => $label)
                <div class="tile" @if($key === 'stale') style="border-color: #8A4B00" @endif>
                    <span class="b {{ $tone[$key][0] }} self-start">{{ $label }}</span>
                    <span class="v">{{ $counts[$key] }}</span>
                    <span class="s">{{ $counts[$key] === 1 ? 'localidad' : 'localidades' }}</span>
                </div>
            @endforeach
        </div>
        <form method="GET" action="{{ route('admin.screens.index', 'locations') }}" class="snow-card flex min-w-[260px] flex-[0_1_340px] flex-col justify-center gap-3.5 px-5 py-4">
            <div>
                <label class="snow-label" for="loc-active">Activo</label>
                <select id="loc-active" name="filter[is_active]" class="snow-input" onchange="this.form.submit()">
                    <option value="">Todos</option><option value="1" @selected((string) $active === '1')>Sí</option><option value="0" @selected((string) $active === '0')>No</option>
                </select>
            </div>
            <div>
                <label class="snow-label" for="loc-sync">Sincronización exitosa en</label>
                <select id="loc-sync" name="sync" class="snow-input" onchange="this.form.submit()">
                    <option value="">Cualquier momento</option>
                    @foreach(['today' => 'Hoy', 'yesterday' => 'Ayer', 'last_week' => 'Últimos 7 días', 'last_month' => 'Últimos 30 días'] as $k => $l)<option value="{{ $k }}" @selected($sync === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <input type="hidden" name="q" value="{{ $q }}">
        </form>
    </section>

    <section aria-label="Listado" class="flex flex-col gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="eyebrow">{{ number_format($items->total()) }} {{ $items->total() === 1 ? 'localidad' : 'localidades' }}@if(mb_strlen($q) > 0 && mb_strlen($q) < 3) · escribe al menos 3 letras para buscar @endif</span>
            <form method="GET" action="{{ route('admin.screens.index', 'locations') }}" class="relative flex items-center" x-data>
                <x-admin.snow.icon name="magnifying-glass" class="pointer-events-none absolute left-3.5 h-4 w-4 text-slate-500" />
                <input class="snow-input !w-72 !rounded-full !pl-10" type="search" name="q" value="{{ $q }}" aria-label="Buscar localidad" placeholder="Buscar (mínimo 3 letras)" autocomplete="off"
                       @if($q !== '') autofocus onfocus="this.setSelectionRange(this.value.length, this.value.length)" @endif
                       x-on:input.debounce.400ms="if ($el.value.length === 0 || $el.value.length >= 3) $el.form.requestSubmit()">
                @if($sync)<input type="hidden" name="sync" value="{{ $sync }}">@endif
                @if($active !== '' && $active !== null)<input type="hidden" name="filter[is_active]" value="{{ $active }}">@endif
            </form>
        </div>

        @forelse($rows as $r)
            @php $loc = $r['loc']; $pin = $r['pin']; [$badge, $bg] = $tone[$pin['status']]; @endphp
            <article class="ticket">
                <div class="flex basis-[170px] flex-col justify-center gap-1.5 rounded-l-[20px] px-4 py-3" style="background: {{ $bg }}">
                    <span class="b {{ $badge }} self-start">{{ $locLabels[$pin['status']] }}</span>
                    <span class="font-mono text-xs text-slate-500">{{ $pin['lastSyncHuman'] ? 'Sincronizó '.$pin['lastSyncHuman'] : 'Nunca sincronizó' }}</span>
                    @if($pin['contingencySinceHuman'])<span class="font-mono text-xs text-red-700">En contingencia {{ $pin['contingencySinceHuman'] }}</span>@endif
                </div>
                <div class="perfv" style="margin: 12px 0"></div>
                <div class="flex min-w-[260px] flex-[1_1_420px] flex-col gap-2.5 px-5 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2.5">
                        <h2 class="!text-xl !font-bold">{{ $loc->name }}</h2>
                        <span class="b {{ $loc->is_active ? 'b-ok' : 'b-mute' }}">{{ $loc->is_active ? 'Activa' : 'Inactiva' }}</span>
                    </div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-x-4 gap-y-2 text-sm">
                        <div><span class="eyebrow">Dirección</span><div class="mt-0.5 {{ $loc->address ? '' : 'text-slate-500' }}">{{ $loc->address ?: 'Sin dirección registrada' }}</div></div>
                        <div><span class="eyebrow">Latitud · Longitud</span><div class="mt-0.5 font-mono text-sm {{ $loc->latitude !== null ? '' : 'text-slate-500 !font-sans !text-base' }}">{{ $loc->latitude !== null ? $loc->latitude.' · '.$loc->longitude : 'Sin coordenadas' }}</div></div>
                        <div><span class="eyebrow">Dispositivos</span><div class="mt-1">{{ $pin['enabledDevices'] }} activos @if($pin['staleDevices'] > 0)<span class="text-amber-800">· {{ $pin['staleDevices'] }} sin sincronizar</span>@endif</div></div>
                        <div><span class="eyebrow">Última sincronización</span><div class="mt-0.5 font-semibold {{ $pin['lastSyncHuman'] ? '' : 'text-amber-800' }}">{{ $pin['lastSyncHuman'] ?? 'Nunca' }}</div></div>
                    </div>
                    @if($canEdit || $canDelete)
                        <div class="flex flex-wrap items-center gap-1.5 border-t-[1.5px] border-slate-100 pt-2.5">
                            @if($canEdit)
                                <a href="{{ route('admin.screens.edit', ['locations', $loc->getKey()]) }}" class="snow-btn-secondary snow-btn-sm">Editar</a>
                            @endif
                            @if($canDelete)
                                <form method="POST" action="{{ route('admin.screens.destroy', ['locations', $loc->getKey()]) }}" class="ml-auto" onsubmit="return confirm('¿Eliminar la localidad «{{ addslashes($loc->name) }}»?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="snow-btn-ghost snow-btn-sm !text-red-700">Eliminar</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <p class="snow-card py-12 text-center text-slate-500">No hay localidades con esos filtros.</p>
        @endforelse

        @if($canEdit)
            <a href="{{ route('admin.screens.create', 'locations') }}" class="flex min-h-[76px] items-center justify-center rounded-[20px] border-2 border-dashed border-slate-300 text-[15px] font-semibold text-primary-600 hover:border-primary-600">Agregar otra localidad</a>
        @endif
        <p class="text-center text-[13px] text-slate-500">Agrega latitud y longitud a cada localidad para ubicarla. Mostrando {{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} de {{ $items->total() }} resultados.</p>
        <div>{{ $items->links() }}</div>
    </section>

</div>
</x-admin.layouts.app>
