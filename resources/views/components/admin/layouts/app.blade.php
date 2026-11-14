@props(['title' => 'Administración'])

@php
    $admin = auth('admin')->user();
    
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

<div class="flex min-h-screen flex-col">
    @include('admin.partials.navbar')

    <div class="flex min-w-0 flex-1 flex-col">
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
