@props(['title' => 'Administración'])

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-canvas text-[13px] font-sans text-snow-800 antialiased">
@php
    $dashActive = request()->routeIs('admin.dashboard');
@endphp
<a href="#contenido" class="sr-only z-[60] rounded-md bg-primary-600 px-3 py-2 text-xs font-semibold text-white focus:not-sr-only focus:fixed focus:left-2 focus:top-2">Saltar al contenido</a>
<div class="min-h-full lg:flex" x-data="{ mobileNav: false }">
    {{-- Mobile --}}
    <div class="sticky top-0 z-40 flex items-center justify-between border-b border-slate-200 bg-snow-800 px-2 py-2 shadow-sm lg:hidden">
        <button type="button" class="rounded-md p-1.5 text-white/70 hover:bg-white/10" @click="mobileNav = true" aria-label="Abrir menú">
            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
        </button>
        <span class="font-mono text-[11px] font-semibold uppercase tracking-widest text-white/90">{{ config('app.name', 'Tap&Go') }}</span>
        <span class="w-8"></span>
    </div>
    <div
        x-show="mobileNav"
        x-cloak
        class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm lg:hidden"
        @click="mobileNav = false"
        @keydown.escape.window="mobileNav = false"
    ></div>
    <aside
        x-show="mobileNav"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="-translate-x-full"
        x-transition:enter-end="translate-x-0"
        class="fixed inset-y-0 left-0 z-50 flex w-[min(17rem,100vw)] flex-col bg-snow-800 shadow-hope-card lg:hidden"
    >
        <div class="flex h-12 items-center justify-between border-b border-slate-700 px-3">
            <span class="text-sm font-bold tracking-tight text-white">Menú</span>
            <button type="button" class="rounded-lg p-1.5 text-white/60 hover:bg-white/10" @click="mobileNav = false" aria-label="Cerrar">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto px-2 py-3">
            @include('admin.partials.sidebar-inner')
        </div>
    </aside>

    {{-- Desktop sidebar --}}
    <aside class="hidden w-[244px] shrink-0 flex-col bg-snow-800 lg:flex lg:sticky lg:top-0 lg:h-screen">
        @include('admin.partials.sidebar-inner')
    </aside>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="snow-topbar sticky top-0 z-30 px-3 py-0 sm:px-4">
            <div class="flex h-14 items-center justify-between gap-2 sm:gap-3">
                <div class="min-w-0 flex items-center gap-2">
                    <nav class="flex min-w-0 items-center gap-1.5 text-[12.5px]" aria-label="Miga de pan">
                        <a href="{{ route('admin.dashboard') }}" class="shrink-0 font-medium text-slate-500 hover:text-primary-600">Panel</a>
                        <span class="text-slate-300">/</span>
                        <span class="truncate font-semibold text-slate-800">{{ $title }}</span>
                    </nav>
                </div>
                <div class="flex shrink-0 items-center gap-1.5 sm:gap-2">
                    {{-- Abre el buscador del panel (admin.partials.command-palette) --}}
                    <button
                        type="button"
                        x-data
                        x-on:click="$dispatch('open-palette')"
                        class="hidden h-8 min-w-0 flex-1 items-center gap-1.5 rounded-md border border-slate-200 bg-snow-50 px-2 text-left transition hover:border-primary-300 sm:flex sm:w-[220px] md:w-64"
                        aria-label="Buscar en el panel"
                    >
                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <span class="flex-1 truncate text-[12px] text-slate-500">Buscar en el panel</span>
                        <span class="hidden shrink-0 rounded border border-slate-200 px-1 font-mono text-[9.5px] text-slate-500 md:inline">⌘K</span>
                    </button>
                    <button type="button" x-data x-on:click="$dispatch('open-palette')" class="snow-icon-btn h-8 w-8 sm:hidden" title="Buscar en el panel" aria-label="Buscar en el panel">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                    </button>
                    <a href="{{ route('admin.2fa.show') }}" class="snow-icon-btn h-8 w-8" title="Verificación en dos pasos" aria-label="Verificación en dos pasos">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                    </a>
                    <form method="POST" action="{{ route('admin.logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="snow-icon-btn h-8 w-8" title="Salir" aria-label="Salir">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M18 12H9m9 0 3-3m-3 3-3-3" /></svg>
                        </button>
                    </form>
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-600" title="{{ auth('admin')->user()?->name }}">
                        {{ collect(explode(' ', auth('admin')->user()?->name ?? '?'))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}
                    </span>
                </div>
            </div>
        </header>

        <main id="contenido" tabindex="-1" class="flex-1 focus:outline-none px-2 py-2 sm:px-3 sm:py-3 lg:px-4">
            @if(session('status'))
                <div class="mb-2 rounded-lg border border-primary-200/80 bg-primary-50 px-2.5 py-1.5 text-xs font-medium text-primary-900 shadow-sm">
                    {{ session('status') }}
                </div>
            @endif
            {{ $slot }}
        </main>

        @include('admin.partials.command-palette')

        <footer class="mt-auto border-t border-slate-200 bg-snow-50 px-3 py-2 text-[11px] text-slate-500">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>© {{ date('Y') }} {{ config('app.name') }}</span>
            </div>
        </footer>
    </div>
</div>
    @stack('scripts')
</body>
</html>
