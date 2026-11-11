<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Verificación en dos pasos — {{ config('app.name', 'Tap&Go') }}</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css'])
        @endif
        <style>
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
                   font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; background: #1e293b; }
        </style>
    </head>
    <body>
        <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
            <h1 class="text-base font-semibold text-slate-900">Verificación en dos pasos</h1>
            <p class="mt-1 text-xs text-slate-500">Ingresa el código de 6 dígitos de tu app de autenticación, o un código de recuperación.</p>

            <form method="POST" action="{{ route('admin.2fa.verify') }}" class="mt-4 space-y-3">
                @csrf
                <input
                    type="text"
                    name="code"
                    placeholder="123456"
                    autofocus
                    autocomplete="one-time-code"
                    class="snow-input w-full text-center font-mono text-lg tracking-[0.3em]"
                >
                @error('code')
                    <p class="text-[11px] font-medium text-red-600">{{ $message }}</p>
                @enderror
                <button type="submit" class="snow-btn-primary w-full">Verificar</button>
            </form>

            <form method="POST" action="{{ route('admin.logout') }}" class="mt-4 border-t border-slate-100 pt-3 text-center">
                @csrf
                <button type="submit" class="text-[10px] font-medium text-slate-500 hover:text-slate-600">Cancelar e ir a inicio de sesión</button>
            </form>
        </div>
    </body>
</html>
