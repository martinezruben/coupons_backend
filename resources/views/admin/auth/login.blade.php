<!DOCTYPE html>
<html lang="es" class="h-full" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Iniciar sesión — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full font-sans antialiased">
<div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
    <a href="{{ route('admin.login') }}" class="hmenu-brand mb-6 text-xl" aria-label="{{ config('app.name', 'Coupify') }}">
        <span class="hmenu-mark h-8 w-8 text-base">{{ mb_strtoupper(mb_substr(config('app.name', 'Coupify'), 0, 1)) }}</span>
        <span>{{ config('app.name', 'Coupify') }}</span>
    </a>

    <div class="w-full max-w-sm">
        <div class="snow-card">
            <div class="border-b border-slate-200 px-5 py-4">
                <h1 class="text-base font-medium text-slate-800">Iniciar sesión</h1>
                <p class="mt-0.5 text-sm text-slate-500">Cuenta de administrador del panel de {{ config('app.name', 'Coupify') }}.</p>
            </div>
            <form method="POST" action="{{ route('admin.login.store') }}" class="space-y-4 p-5">
                @csrf
                <div>
                    <label class="snow-label" for="email">Correo electrónico</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" class="snow-input" required autocomplete="username">
                    @error('email')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="snow-label" for="password">Contraseña</label>
                    <input id="password" name="password" type="password" class="snow-input" required autocomplete="current-password">
                </div>
                <button type="submit" class="snow-btn-primary w-full">Entrar al panel</button>
            </form>
        </div>

        @if(isset($dbStatus))
            <p class="mt-4 flex items-center justify-center gap-2 text-xs text-slate-500">
                @if($dbStatus === 'OK')
                    <span class="inline-block h-2 w-2 rounded-full bg-green-600"></span> Base de datos conectada
                @else
                    <span class="inline-block h-2 w-2 rounded-full bg-yellow-600"></span> Sin administradores activos
                @endif
            </p>
        @endif
        <p class="mt-6 text-center text-xs text-slate-500">© {{ date('Y') }} {{ config('app.name') }} · Acceso seguro</p>
    </div>
</div>
</body>
</html>
