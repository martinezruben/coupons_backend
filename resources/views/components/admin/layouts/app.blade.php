@props(['title' => 'Administración'])

@php
    $admin = auth('admin')->user();
    $initials = collect(explode(' ', $admin?->name ?? '?'))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
@endphp
<!DOCTYPE html>
<html lang="es" class="h-full" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full font-sans antialiased">
<a href="#contenido" class="sr-only z-[1060] snow-btn-primary focus:not-sr-only focus:fixed focus:left-2 focus:top-2">Saltar al contenido</a>

<div class="min-h-screen lg:flex" x-data="{ nav: false, user: false }" x-on:keydown.escape.window="nav = false; user = false">
    {{-- Escritorio: barra lateral fija --}}
    <aside class="sidenav hidden shrink-0 lg:sticky lg:top-0 lg:flex lg:h-screen" aria-label="Menú">
        @include('admin.partials.sidebar')
    </aside>

    {{-- Móvil: la misma barra como panel deslizable, sin tapar el contenido al cerrar --}}
    <div x-show="nav" x-cloak class="fixed inset-0 z-[1045] bg-gray-950/50 lg:hidden" x-on:click="nav = false" aria-hidden="true"></div>
    <aside x-show="nav" x-cloak class="sidenav fixed inset-y-0 left-0 z-[1050] max-w-full shadow-xl lg:hidden" aria-label="Menú">
        <div class="h-full">@include('admin.partials.sidebar')</div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="topbar">
            <button type="button" class="snow-icon-btn h-9 w-9 lg:hidden" :aria-expanded="nav ? 'true' : 'false'" aria-label="Abrir menú" x-on:click="nav = true">
                <x-admin.snow.icon name="bars-3" class="h-5 w-5" />
            </button>
            <span class="font-semibold lg:hidden">{{ config('app.name', 'Coupify') }}</span>

            <div class="ml-auto flex items-center gap-3">
                {{-- Abre el buscador del panel (admin.partials.command-palette) --}}
                <button type="button" x-data x-on:click="$dispatch('open-palette')" class="snow-btn-secondary hidden sm:inline-flex" aria-label="Buscar en el panel">
                    <x-admin.snow.icon name="magnifying-glass" class="h-5 w-5 text-slate-500" />
                    <span class="text-slate-500">Buscar</span>
                    <kbd class="rounded border border-slate-200 px-1 text-2xs text-slate-500">⌘K</kbd>
                </button>
                <button type="button" x-data x-on:click="$dispatch('open-palette')" class="snow-icon-btn h-9 w-9 sm:hidden" aria-label="Buscar en el panel">
                    <x-admin.snow.icon name="magnifying-glass" class="h-5 w-5" />
                </button>

                <div class="relative" x-on:click.outside="user = false">
                    <button type="button" class="avatar" aria-haspopup="menu" :aria-expanded="user ? 'true' : 'false'" aria-label="Cuenta de {{ $admin?->name }}" x-on:click="user = !user">{{ $initials }}</button>
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
        </header>

        <main id="contenido" tabindex="-1" class="mx-auto w-full max-w-[1320px] flex-1 px-4 py-6 focus:outline-none sm:px-6">
            <div class="page-header">
                <nav aria-label="Miga de pan" class="page-pretitle">
                    <a href="{{ route('admin.dashboard') }}" class="hover:underline">Panel</a>
                    <span aria-hidden="true">/</span>
                    <span>{{ $title }}</span>
                </nav>
                <h1 class="page-title">{{ $title }}</h1>
            </div>

            @if(session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif
            @if($errors->has('coupon'))
                <div class="alert alert-danger" role="alert">{{ $errors->first('coupon') }}</div>
            @endif

            {{ $slot }}
        </main>

        @include('admin.partials.command-palette')

        <footer class="border-t border-slate-200 bg-white px-4 py-3 text-xs text-slate-500 sm:px-6">
            <div class="mx-auto flex w-full max-w-[1320px] flex-wrap items-center justify-between gap-2">
                <span>© {{ date('Y') }} {{ config('app.name') }}</span>
            </div>
        </footer>
    </div>
</div>
@stack('scripts')
</body>
</html>
