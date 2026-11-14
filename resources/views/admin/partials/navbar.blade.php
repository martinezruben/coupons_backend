{{--
    Navegación horizontal (navbar de Tabler/UI): marca, Inicio y un menú desplegable por grupo
    (config/admin_nav_groups.php). En móvil se pliega en un panel. Solo muestra lo que el usuario puede ver.
--}}
@php
    $admin = auth('admin')->user();
    $groups = array_values(array_filter(\App\Support\AdminNavigation::groups($admin), fn ($g) => $g['items'] !== []));
    $dashActive = request()->routeIs('admin.dashboard');
    $appName = config('app.name', 'Coupify');
    $initials = collect(explode(' ', $admin?->name ?? '?'))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
@endphp
<header class="topnav" x-data="{ open: null, nav: false, user: false }"
        x-on:keydown.escape.window="open = null; nav = false; user = false" x-on:click.outside="open = null; user = false">
    <div class="topnav-inner">
        <button type="button" class="snow-icon-btn h-9 w-9 lg:hidden" :aria-expanded="nav ? 'true' : 'false'" aria-label="Abrir menú" x-on:click="nav = !nav">
            <x-admin.snow.icon name="bars-3" class="h-5 w-5" />
        </button>

        <a href="{{ route('admin.dashboard') }}" class="topnav-brand" aria-label="{{ $appName }}: inicio">
            <span class="brand-mark">{{ strtoupper(mb_substr($appName, 0, 1)) }}</span>
            <span>{{ $appName }}</span>
        </a>

        {{-- Escritorio: enlaces y desplegables en línea --}}
        <nav class="topnav-menu hidden lg:flex" aria-label="Navegación principal">
            <a href="{{ route('admin.dashboard') }}" class="topnav-link {{ $dashActive ? 'is-active' : '' }}" @if($dashActive) aria-current="page" @endif>
                <x-admin.snow.icon name="home" class="h-5 w-5" /> Inicio
            </a>
            @foreach($groups as $group)
                @php $groupActive = collect($group['items'])->contains(fn ($i) => $i['active']); @endphp
                <div class="relative">
                    <button type="button" class="topnav-link {{ $groupActive ? 'is-active' : '' }}" aria-haspopup="true"
                            :aria-expanded="open === '{{ $group['key'] }}' ? 'true' : 'false'"
                            x-on:click="open = open === '{{ $group['key'] }}' ? null : '{{ $group['key'] }}'">
                        <x-admin.snow.icon :name="$group['icon'] ?? 'squares-2x2'" class="h-5 w-5" />
                        {{ $group['label'] }}
                        <svg class="h-3 w-3 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div x-show="open === '{{ $group['key'] }}'" x-cloak class="dropdown-menu" style="margin-top: .25rem; min-width: 15rem">
                        @foreach($group['items'] as $item)
                            <a href="{{ $item['url'] }}" class="dropdown-item {{ $item['active'] ? 'is-active' : '' }}" @if($item['active']) aria-current="page" @endif>
                                <x-admin.snow.icon :name="$item['icon']" class="h-5 w-5 shrink-0 text-slate-500" />
                                <span class="min-w-0 flex-1">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-3">
            {{-- Abre el buscador del panel (admin.partials.command-palette) --}}
            <button type="button" x-on:click="$dispatch('open-palette')" class="snow-btn-secondary hidden sm:inline-flex" aria-label="Buscar en el panel">
                <x-admin.snow.icon name="magnifying-glass" class="h-5 w-5 text-slate-500" />
                <span class="text-slate-500">Buscar</span>
                <kbd class="rounded border border-slate-200 px-1 text-2xs text-slate-500">⌘K</kbd>
            </button>
            <button type="button" x-on:click="$dispatch('open-palette')" class="snow-icon-btn h-9 w-9 sm:hidden" aria-label="Buscar en el panel">
                <x-admin.snow.icon name="magnifying-glass" class="h-5 w-5" />
            </button>

            <div class="relative">
                <button type="button" class="avatar" aria-haspopup="menu" :aria-expanded="user ? 'true' : 'false'" aria-label="Cuenta de {{ $admin?->name }}" x-on:click="user = !user; open = null">{{ $initials }}</button>
                <div x-show="user" x-cloak class="dropdown-menu is-end" role="menu" style="margin-top: .25rem">
                    <div class="dropdown-header">
                        <span class="block text-sm font-semibold normal-case tracking-normal text-slate-800">{{ $admin?->name }}</span>
                        <span class="block text-xs font-normal normal-case tracking-normal">{{ $admin?->email }}</span>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a href="{{ route('admin.2fa.show') }}" class="dropdown-item" role="menuitem">
                        <x-admin.snow.icon name="lock-closed" class="h-5 w-5 text-slate-500" />
                        Verificación en dos pasos
                    </a>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item" role="menuitem">
                            <x-admin.snow.icon name="arrow-right-on-rectangle" class="h-5 w-5 text-slate-500" />
                            Salir
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Móvil: todo el menú en un panel apilado --}}
    <nav x-show="nav" x-cloak class="topnav-mobile lg:hidden" aria-label="Navegación principal (móvil)">
        <a href="{{ route('admin.dashboard') }}" class="sidenav-link {{ $dashActive ? 'is-active' : '' }}">
            <x-admin.snow.icon name="home" class="h-5 w-5 shrink-0" /> Inicio
        </a>
        @foreach($groups as $group)
            <div class="mt-3">
                <span class="sidenav-group-btn">{{ $group['label'] }}</span>
                @foreach($group['items'] as $item)
                    <a href="{{ $item['url'] }}" class="sidenav-link {{ $item['active'] ? 'is-active' : '' }}" @if($item['active']) aria-current="page" @endif>
                        <x-admin.snow.icon :name="$item['icon']" class="h-5 w-5 shrink-0 {{ $item['active'] ? '' : 'text-slate-500' }}" />
                        <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</header>
