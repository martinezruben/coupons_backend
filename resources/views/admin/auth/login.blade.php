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
<div class="flex min-h-screen flex-wrap">
    {{-- Panel de marca --}}
    <section class="flex min-h-[320px] flex-[1_1_520px] flex-col justify-between gap-10 bg-primary-600 px-[clamp(28px,5vw,72px)] py-14 text-white">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-11 items-center justify-center rounded-[10px] font-display text-[19px] font-bold text-primary-600" style="background: radial-gradient(circle at 0 50%, #0F3D33 0 5.5px, transparent 6px), radial-gradient(circle at 100% 50%, #0F3D33 0 5.5px, transparent 6px), #D4F76B" aria-hidden="true">{{ mb_strtolower(mb_substr(config('app.name', 'Coupify'), 0, 1)) }}</span>
            <span class="brand-word text-white">{{ config('app.name', 'Coupify') }}</span>
        </div>
        <div class="flex max-w-[560px] flex-col gap-7">
            <h2 class="font-display text-[clamp(2.5rem,5vw,3.6rem)] font-bold leading-[1.02]">Todos tus cupones, <span class="text-lime-300">bajo control.</span></h2>
            <p class="max-w-[460px] text-[17px] text-[#C9DDD0]">Lotes, activaciones, saldos y cobros en un solo panel.</p>
            <div class="flex max-w-[420px] drop-shadow-[0_18px_30px_rgba(0,0,0,.25)]" aria-hidden="true">
                <div class="flex flex-1 flex-col gap-1 rounded-l-[18px] bg-lime-300 px-6 py-5 text-primary-600"><span class="eyebrow !text-primary-600">Cupón</span><span class="font-display text-[40px] font-bold leading-none">$1,000</span></div>
                <div class="w-0 border-l-2 border-dashed border-primary-600 bg-lime-300"></div>
                <div class="flex basis-24 items-center justify-center rounded-r-[18px] bg-lime-300"><span class="font-mono text-xs tracking-[0.14em] text-primary-600" style="writing-mode: vertical-rl; transform: rotate(180deg)">{{ strtoupper(config('app.name', 'Coupify')) }}</span></div>
            </div>
        </div>
        <span class="font-mono text-xs text-[#B9D3C5]">© {{ date('Y') }} {{ config('app.name') }} · Acceso seguro</span>
    </section>

    {{-- Formulario --}}
    <section class="flex flex-[1_1_480px] items-center justify-center bg-slate-50 px-6 py-12">
        <div class="w-full max-w-[440px]">
            <form method="POST" action="{{ route('admin.login.store') }}" class="snow-card flex flex-col gap-5 p-9">
                @csrf
                <div class="flex flex-col gap-2">
                    <span class="eyebrow">Acceso seguro</span>
                    <h1 class="font-display text-4xl font-bold leading-[1.05]">Iniciar sesión</h1>
                    <p class="text-sm text-slate-600">Cuenta de administrador del panel de {{ config('app.name', 'Coupify') }}.</p>
                </div>
                <div>
                    <label class="snow-label" for="email">Correo electrónico</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" class="snow-input" required autocomplete="username">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="snow-label" for="password">Contraseña</label>
                    <input id="password" name="password" type="password" class="snow-input" required autocomplete="current-password">
                </div>
                <button type="submit" class="snow-btn-primary !min-h-[3.125rem] w-full text-[15px]">Entrar al panel</button>
            </form>

            @if(isset($dbStatus))
                <p class="mt-4 flex items-center justify-center gap-2 text-xs text-slate-500">
                    @if($dbStatus === 'OK')
                        <span class="inline-block h-2 w-2 rounded-full bg-green-500"></span> Base de datos conectada
                    @else
                        <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span> Sin administradores activos
                    @endif
                </p>
            @endif
        </div>
    </section>
</div>
</body>
</html>
