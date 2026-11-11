<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Iniciar sesión — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-canvas font-sans antialiased">
<div class="flex min-h-full flex-col lg:flex-row">
    <div class="relative flex flex-1 flex-col justify-between overflow-hidden bg-gradient-to-br from-primary-600 via-primary-600 to-primary-900 px-8 py-12 text-white lg:w-[46%] lg:max-w-xl lg:px-12">
        <div class="absolute inset-0 opacity-40" style="background-image: radial-gradient(circle at 15% 25%, white 0%, transparent 42%), radial-gradient(circle at 85% 75%, rgba(255,255,255,0.12) 0%, transparent 45%);"></div>
        <div class="relative">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/15 text-lg font-bold shadow-lg backdrop-blur">{{ mb_strtoupper(mb_substr(config('app.name', 'Tap&Go'), 0, 1)) }}</span>
                <span class="text-sm font-semibold tracking-wide text-white/95">{{ config('app.name', 'Tap&Go') }}</span>
            </div>
            <h1 class="mt-14 max-w-md text-3xl font-bold leading-tight tracking-tight lg:text-4xl">
                Panel de administración
            </h1>
            <p class="mt-4 max-w-sm text-sm leading-relaxed text-primary-100/95">
                Localidades, dispositivos, licencias y sincronización — gestión completa de tu POS Tap&Go.
            </p>
        </div>
        <p class="relative text-xs text-primary-200/80">© {{ date('Y') }} · Acceso seguro</p>
    </div>

    <div class="flex flex-1 items-center justify-center px-4 py-10 sm:px-8">
        <div class="w-full max-w-md">
            <div class="snow-card rounded-3xl shadow-hope-card">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 class="text-lg font-bold tracking-tight text-slate-900">Iniciar sesión</h2>
                    <p class="mt-1 text-xs text-slate-500">Cuenta de administrador del backend.</p>
                </div>
                <form method="POST" action="{{ route('admin.login.store') }}" class="space-y-5 px-6 py-6">
                    @csrf
                    <div>
                        <label class="snow-label" for="email">Correo electrónico</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" class="snow-input" required autocomplete="username">
                        @error('email')
                            <p class="mt-1.5 text-xs font-medium text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="snow-label" for="password">Contraseña</label>
                        <input id="password" name="password" type="password" class="snow-input" required autocomplete="current-password">
                    </div>
                    <button type="submit" class="snow-btn-primary w-full py-3 text-base shadow-md shadow-primary-600/20">Entrar al panel</button>
                    <div class="mt-3 flex justify-center">
                        @if(isset($dbStatus))
                            @if($dbStatus === 'OK')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-800 ring-1 ring-emerald-200">
                                    <svg class="h-2.5 w-2.5 shrink-0 fill-current" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                    Base de datos conectada
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-semibold text-amber-800 ring-1 ring-amber-200">
                                    <svg class="h-2.5 w-2.5 shrink-0 fill-current" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/></svg>
                                    Sin administradores activos
                                </span>
                            @endif
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
