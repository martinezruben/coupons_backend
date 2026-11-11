<x-admin.layouts.app :title="'Dashboard técnico'">
    <div class="space-y-4 pb-6">
        @include('admin.partials.dashboard-tabs')

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($kpis as $card)
                @include('admin.partials.dashboard-kpi-card', ['card' => $card])
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="snow-card flex max-h-[min(408px,85vh)] flex-col overflow-hidden p-3 lg:min-h-[380px] xl:h-[408px] xl:max-h-none">
                <div class="shrink-0">
                    <h3 class="text-sm font-semibold text-slate-900">Actividad reciente</h3>
                    <p class="text-[11px] text-slate-500">Sync y API</p>
                </div>
                <ul class="mt-2 min-h-0 flex-1 space-y-1.5 overflow-y-auto overscroll-contain pr-0.5 [scrollbar-gutter:stable]">
                    @forelse ($activity as $row)
                        @php
                            $strip = match ($row['tone']) {
                                'emerald' => 'border-l-emerald-500',
                                'rose' => 'border-l-rose-500',
                                'sky' => 'border-l-sky-500',
                                default => 'border-l-amber-500',
                            };
                            $iconBox = match ($row['tone']) {
                                'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-600',
                                'rose' => 'border-rose-200 bg-rose-50 text-rose-600',
                                'sky' => 'border-sky-200 bg-sky-50 text-sky-600',
                                default => 'border-amber-200 bg-amber-50 text-amber-600',
                            };
                            $pulse = match ($row['tone']) {
                                'emerald' => 'bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.7)]',
                                'rose' => 'bg-rose-400 shadow-[0_0_8px_rgba(251,113,133,0.7)]',
                                'sky' => 'bg-sky-400 shadow-[0_0_8px_rgba(56,189,248,0.7)]',
                                default => 'bg-amber-400 shadow-[0_0_8px_rgba(251,191,36,0.7)]',
                            };
                            $dirBadge = match ($row['direction']) {
                                'Pull' => 'border-sky-200/90 bg-sky-50/90 text-sky-900 ring-sky-100',
                                'Push' => 'border-violet-200/90 bg-violet-50/90 text-violet-900 ring-violet-100',
                                default => 'border-slate-200/90 bg-slate-100/90 text-slate-800 ring-slate-100',
                            };
                            $statusLabel = match ($row['tone']) {
                                'emerald' => 'Correcto',
                                'rose' => 'Fallo',
                                'sky' => 'OK',
                                default => 'Aviso',
                            };
                        @endphp
                        <li
                            class="animate-act-in"
                            style="animation-delay: {{ min($loop->index * 45, 360) }}ms"
                        >
                            <div
                                class="group relative flex gap-2 overflow-hidden rounded-xl border border-slate-200/80 bg-white pl-2 transition duration-200 hover:border-slate-300 hover:bg-slate-50/60 {{ $strip }} border-l-[3px]"
                            >
                                <div
                                    class="relative mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $iconBox }}"
                                >
                                    @if ($row['direction'] === 'Pull')
                                        <span class="text-base font-bold leading-none">↓</span>
                                    @elseif ($row['direction'] === 'Push')
                                        <span class="text-base font-bold leading-none">↑</span>
                                    @else
                                        <span class="px-0.5 text-[10px] font-bold uppercase tracking-wider">API</span>
                                    @endif
                                </div>
                                <div class="relative min-w-0 flex-1 py-1.5 pr-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="truncate text-xs font-semibold leading-tight tracking-tight text-slate-900">
                                            {{ $row['location'] }}
                                        </p>
                                        <span class="inline-flex shrink-0 items-center gap-0.5 text-[11px] font-medium tabular-nums text-slate-500">
                                            <x-admin.snow.icon name="clock" class="h-3 w-3 text-slate-500" />
                                            {{ $row['time_human'] }}
                                        </span>
                                    </div>
                                    <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="inline-flex min-w-0 max-w-full items-center gap-1 text-[11px] text-slate-600">
                                            <x-admin.snow.icon name="device-phone-mobile" class="h-3 w-3 shrink-0 text-slate-500" />
                                            <span class="truncate">{{ $row['device'] }}</span>
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $dirBadge }}"
                                        >
                                            {{ $row['direction'] }}
                                        </span>
                                        <span class="inline-flex items-center gap-1">
                                            <span class="h-1.5 w-1.5 rounded-full {{ $pulse }}"></span>
                                            <span class="text-[11px] font-medium text-slate-500">{{ $statusLabel }}</span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="flex list-none flex-col items-center justify-center gap-2 py-10 text-center">
                            <div
                                class="flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-500"
                            >
                                <x-admin.snow.icon name="arrow-path" class="h-5 w-5" />
                            </div>
                            <p class="max-w-[12rem] text-xs leading-snug text-slate-500">Sin actividad reciente en sync ni API.</p>
                        </li>
                    @endforelse
                </ul>
            </div>
            <div class="snow-card p-4">
                <h3 class="text-sm font-semibold text-slate-900">Sincronizaciones por día</h3>
                <p class="text-[11px] text-slate-500">Correctas vs fallidas (14 días)</p>
                <div data-chart="sync-stacked" class="mt-2 min-h-[300px] w-full"></div>
            </div>
        </div>
    </div>

    <x-modal name="dash-contingencies" maxWidth="lg">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Localidades en contingencia ({{ count($contingencies) }})</h3>
            <button type="button" x-on:click="$dispatch('close-modal', 'dash-contingencies')" class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar">
                <x-admin.snow.icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
            <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                <thead class="snow-table-head"><tr><th class="px-4 py-2 font-semibold">Localidad</th><th class="px-4 py-2 font-semibold">En contingencia desde</th></tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($contingencies as $row)
                        <tr><td class="px-4 py-2 font-medium text-slate-800">{{ $row['name'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['since'] }}</td></tr>
                    @empty
                        <tr><td colspan="2" class="px-4 py-6 text-center text-slate-500">Ninguna localidad en contingencia.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-modal>
    <x-modal name="dash-devices-no-sync" maxWidth="lg">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Dispositivos sin sincronizar hace más de 4 h ({{ count($devicesNoSync) }})</h3>
            <button type="button" x-on:click="$dispatch('close-modal', 'dash-devices-no-sync')" class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar">
                <x-admin.snow.icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
            <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                <thead class="snow-table-head"><tr><th class="px-4 py-2 font-semibold">Dispositivo</th><th class="px-4 py-2 font-semibold">Localidad</th><th class="px-4 py-2 font-semibold">Horas sin sync</th></tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($devicesNoSync as $row)
                        <tr><td class="px-4 py-2 font-medium text-slate-800">{{ $row['name'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['location'] }}</td><td class="px-4 py-2 tabular-nums text-slate-600">{{ $row['hours_ago'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Todos los dispositivos están al día.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-modal>
    <x-modal name="dash-licenses-expiring" maxWidth="lg">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Licencias por vencer en 7 días ({{ count($licensesExpiring) }})</h3>
            <button type="button" x-on:click="$dispatch('close-modal', 'dash-licenses-expiring')" class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar">
                <x-admin.snow.icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
            <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                <thead class="snow-table-head"><tr><th class="px-4 py-2 font-semibold">Dispositivo</th><th class="px-4 py-2 font-semibold">Localidad</th><th class="px-4 py-2 font-semibold">Vence</th></tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($licensesExpiring as $row)
                        <tr><td class="px-4 py-2 font-medium text-slate-800">{{ $row['device'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['location'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['expires_in'] }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Ninguna licencia por vencer.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-modal>

    <x-modal name="dash-users-no-pin" maxWidth="lg">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Usuarios sin PIN válido sin conexión ({{ $usersNoPin['count'] }})</h3>
            <button type="button" x-on:click="$dispatch('close-modal', 'dash-users-no-pin')" class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar">
                <x-admin.snow.icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>
        <p class="border-b border-slate-100 bg-amber-50 px-4 py-2 text-xs text-amber-900">
            Su PIN correcto fallará en la app sin conexión. Se corrige guardando de nuevo su contraseña en
            @if (auth('admin')->user()?->can(\App\Support\AdminRbac::permissionsForScreen('android-users')['edit']))
                <a class="font-semibold underline" href="{{ route('admin.screens.index', ['screen' => 'android-users', 'filter' => ['pin' => '1']]) }}">Usuarios POS</a>.
            @else
                Usuarios POS.
            @endif
        </p>
        <div class="max-h-[60vh] overflow-y-auto">
            <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                <thead class="snow-table-head"><tr><th class="px-4 py-2 font-semibold">Usuario</th><th class="px-4 py-2 font-semibold">Nombre</th><th class="px-4 py-2 font-semibold">Rol</th><th class="px-4 py-2 font-semibold">Localidades</th></tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($usersNoPin['rows'] as $row)
                        <tr><td class="px-4 py-2 font-medium text-slate-800">{{ $row['username'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['name'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['role'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['location'] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Todos los usuarios tienen un PIN válido sin conexión.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($usersNoPin['count'] > count($usersNoPin['rows']))
                <p class="px-4 py-2 text-xs text-slate-500">Se muestran los primeros {{ count($usersNoPin['rows']) }} de {{ $usersNoPin['count'] }}.</p>
            @endif
        </div>
    </x-modal>
    <x-modal name="dash-outdated-devices" maxWidth="lg">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-semibold text-slate-900">Dispositivos con app desactualizada ({{ count($outdatedDevices) }})</h3>
            <button type="button" x-on:click="$dispatch('close-modal', 'dash-outdated-devices')" class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-600" aria-label="Cerrar">
                <x-admin.snow.icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
            <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                <thead class="snow-table-head"><tr><th class="px-4 py-2 font-semibold">Dispositivo</th><th class="px-4 py-2 font-semibold">Localidad</th><th class="px-4 py-2 font-semibold">Versión</th><th class="px-4 py-2 font-semibold">Mínima</th></tr></thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($outdatedDevices as $row)
                        <tr><td class="px-4 py-2 font-medium text-slate-800">{{ $row['name'] }}</td><td class="px-4 py-2 text-slate-600">{{ $row['location'] }}</td><td class="px-4 py-2 font-mono text-amber-700">{{ $row['version'] }}</td><td class="px-4 py-2 font-mono text-slate-600">{{ $row['minimum'] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Todos los dispositivos tienen la versión mínima o superior.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-modal>

    @push('scripts')
        <script type="application/json" id="dashboard-chart-data">@json($chartPayload)</script>
        @vite(['resources/js/dashboard.js'])
    @endpush
</x-admin.layouts.app>
