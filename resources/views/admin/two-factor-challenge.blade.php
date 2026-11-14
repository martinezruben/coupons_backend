<!DOCTYPE html>
<html lang="es" class="h-full" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Verificación en dos pasos — {{ config('app.name', 'Coupify') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-full font-sans antialiased">
        <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
            <span class="hmenu-brand mb-6 text-xl">
                <span class="hmenu-mark h-8 w-8 text-base">{{ mb_strtoupper(mb_substr(config('app.name', 'Coupify'), 0, 1)) }}</span>
                <span>{{ config('app.name', 'Coupify') }}</span>
            </span>

            <div class="snow-card w-full max-w-sm">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h1 class="text-base font-medium text-slate-800">Verificación en dos pasos</h1>
                    <p class="mt-0.5 text-sm text-slate-500">Ingresa el código de 6 dígitos de tu app de autenticación, o un código de recuperación.</p>
                </div>

                <form method="POST" action="{{ route('admin.2fa.verify') }}" class="space-y-3 p-5">
                    @csrf
                    <label class="snow-label" for="code">Código</label>
                    <input
                        id="code"
                        type="text"
                        name="code"
                        placeholder="123456"
                        autofocus
                        autocomplete="one-time-code"
                        class="snow-input w-full text-center font-mono text-lg tracking-[0.3em]"
                    >
                    @error('code')
                        <p class="text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="snow-btn-primary w-full">Verificar</button>
                </form>

                <form method="POST" action="{{ route('admin.logout') }}" class="border-t border-slate-200 bg-slate-100 px-5 py-3 text-center">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-slate-500 hover:text-slate-800">Cancelar e ir a inicio de sesión</button>
                </form>
            </div>
        </div>
    </body>
</html>
