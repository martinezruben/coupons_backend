{{--
    Menú horizontal (HorizontalMenu / HorizontalMenuMobile del sistema Tabler/UI):
    barra de 56px, máximo 6 elementos de primer nivel, desplegables que se abren con clic
    (no con hover) y se cierran con Esc o al hacer clic fuera. Debajo de 992px se colapsa
    detrás de un botón de hamburguesa, con los grupos como acordeón.
--}}
@php
    $admin = auth('admin')->user();
    $groups = \App\Support\AdminNavigation::groups($admin);
    $groups = array_values(array_filter($groups, fn ($g) => $g['items'] !== []));
    $dashActive = request()->routeIs('admin.dashboard');
    $initials = collect(explode(' ', $admin?->name ?? '?'))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    $appName = config('app.name', 'Coupify');
@endphp
<header
    class="hmenu"
    x-data="{ open: null, mobile: false, group: null }"
    x-on:keydown.escape.window="open = null; mobile = false"
    x-on:click.outside="open = null"
>
    <div class="hmenu-bar">
        <a href="{{ route('admin.dashboard') }}" class="hmenu-brand" aria-label="{{ $appName }}: inicio">
            <span class="hmenu-mark">{{ strtoupper(mb_substr($appName, 0, 1)) }}</span>
            <span>{{ $appName }}</span>
        </a>

        <ul class="hmenu-nav" role="menubar" aria-label="Navegación principal">
            <li class="hmenu-item" role="none">
                <a href="{{ route('admin.dashboard') }}" role="menuitem" class="hmenu-link {{ $dashActive ? 'is-active' : '' }}" @if($dashActive) aria-current="page" @endif>Inicio</a>
            </li>
            @foreach($groups as $group)
                @php
                    $groupActive = collect($group['items'])->contains(fn ($i) => $i['active']);
                    $mega = count($group['items']) >= 6;
                @endphp
                <li class="hmenu-item" :class="{ 'is-open': open === '{{ $group['key'] }}' }" role="none">
                    <button
                        type="button"
                        role="menuitem"
                        class="hmenu-link hmenu-chevron {{ $groupActive ? 'is-active' : '' }}"
                        aria-haspopup="menu"
                        :aria-expanded="open === '{{ $group['key'] }}' ? 'true' : 'false'"
                        x-on:click="open = open === '{{ $group['key'] }}' ? null : '{{ $group['key'] }}'"
                    >{{ $group['label'] }}</button>
                    <div
                        x-show="open === '{{ $group['key'] }}'"
                        x-cloak
                        class="dropdown-menu {{ $mega ? 'is-mega' : '' }}"
                        role="menu"
                        aria-label="{{ $group['label'] }}"
                    >
                        @foreach($group['items'] as $item)
                            <a
                                href="{{ $item['url'] }}"
                                class="dropdown-item"
                                role="menuitem"
                                @if($item['active']) aria-current="page" @endif
                            >
                                <x-admin.snow.icon :name="$item['icon']" class="h-5 w-5 shrink-0 text-slate-500" />
                                <span class="min-w-0">
                                    <span class="block {{ $item['active'] ? 'font-semibold' : '' }}">{{ $item['label'] }}</span>
                                    @if(! empty($item['description']))
                                        <small>{{ $item['description'] }}</small>
                                    @endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="hmenu-end">
            {{-- Abre el buscador del panel (admin.partials.command-palette) --}}
            <button
                type="button"
                x-data
                x-on:click="$dispatch('open-palette')"
                class="snow-btn-secondary hidden sm:inline-flex"
                aria-label="Buscar en el panel"
            >
                <x-admin.snow.icon name="magnifying-glass" class="h-5 w-5 text-slate-500" />
                <span class="text-slate-500">Buscar</span>
                <kbd class="rounded border border-slate-200 px-1 text-2xs text-slate-500">⌘K</kbd>
            </button>
            <button type="button" x-data x-on:click="$dispatch('open-palette')" class="snow-icon-btn h-9 w-9 sm:hidden" aria-label="Buscar en el panel">
                <x-admin.snow.icon name="magnifying-glass" class="h-5 w-5" />
            </button>

            <div class="relative" :class="{ 'is-open': open === 'user' }">
                <button
                    type="button"
                    class="avatar"
                    aria-haspopup="menu"
                    :aria-expanded="open === 'user' ? 'true' : 'false'"
                    aria-label="Cuenta de {{ $admin?->name }}"
                    x-on:click="open = open === 'user' ? null : 'user'"
                >{{ $initials }}</button>
                <div x-show="open === 'user'" x-cloak class="dropdown-menu is-end" role="menu" style="margin-top: .25rem">
                    <div class="dropdown-header normal-case">
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

            <button
                type="button"
                class="navbar-toggler"
                :aria-expanded="mobile ? 'true' : 'false'"
                aria-controls="hmenu-collapse"
                :aria-label="mobile ? 'Cerrar menú' : 'Abrir menú'"
                x-on:click="mobile = !mobile"
            ><span></span><span></span><span></span></button>
        </div>
    </div>

    {{-- Colapsado (< 992px): los grupos son un acordeón de uno abierto a la vez --}}
    <nav id="hmenu-collapse" class="hmenu-collapse" x-show="mobile" x-cloak aria-label="Navegación principal">
        <a href="{{ route('admin.dashboard') }}" class="hmenu-row {{ $dashActive ? 'is-active' : '' }}" @if($dashActive) aria-current="page" @endif>Inicio</a>
        @foreach($groups as $group)
            @php $groupActive = collect($group['items'])->contains(fn ($i) => $i['active']); @endphp
            <div>
                <button
                    type="button"
                    class="hmenu-row {{ $groupActive ? 'is-active' : '' }}"
                    :aria-expanded="group === '{{ $group['key'] }}' ? 'true' : 'false'"
                    x-on:click="group = group === '{{ $group['key'] }}' ? null : '{{ $group['key'] }}'"
                >
                    <span>{{ $group['label'] }}</span>
                    <span aria-hidden="true" x-text="group === '{{ $group['key'] }}' ? '−' : '+'"></span>
                </button>
                <div class="nested" x-show="group === '{{ $group['key'] }}'" x-cloak>
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['url'] }}" class="hmenu-row {{ $item['active'] ? 'is-active' : '' }}" @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>
</header>
