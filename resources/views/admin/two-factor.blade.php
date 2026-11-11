<x-admin.layouts.app :title="'Verificación en dos pasos'">
    <div class="mx-auto max-w-2xl space-y-4 pb-6">
        @if(session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">{{ session('status') }}</div>
        @endif

        <div class="snow-card rounded-xl border border-slate-200/90 bg-white p-4 shadow-hope-card">
            <h2 class="text-sm font-semibold text-slate-900">Verificación en dos pasos (TOTP)</h2>
            <p class="mt-1 text-[11px] text-slate-500">
                Protege tu cuenta con un código de 6 dígitos que se renueva cada 30 segundos en tu app de autenticación
                (Google Authenticator, Authy, 1Password, etc.).
            </p>

            <p class="mt-3 inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10px] font-semibold {{ auth('admin')->user()->totp_enabled ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200' }}">
                {{ auth('admin')->user()->totp_enabled ? '● 2FA activado' : '○ 2FA desactivado' }}
            </p>
        </div>

        @if(!auth('admin')->user()->totp_enabled)
            @if($qrSvg)
                <div class="snow-card rounded-xl border border-slate-200/90 bg-white p-4 shadow-hope-card">
                    <h3 class="text-xs font-semibold text-slate-900">1. Escanea el código con tu app</h3>
                    <div class="mt-2 flex flex-col items-center gap-2 sm:flex-row sm:items-start">
                        <div class="shrink-0 rounded-lg border border-slate-200 bg-white p-2">{!! $qrSvg !!}</div>
                        <div class="min-w-0 text-[11px] text-slate-600">
                            <p>Si no puedes escanear, ingresa esta clave manualmente:</p>
                            <code class="mt-1 block break-all rounded bg-slate-50 px-2 py-1 font-mono text-[10px] text-slate-800">{{ $secretText }}</code>
                        </div>
                    </div>

                    <h3 class="mt-4 text-xs font-semibold text-slate-900">2. Confirma con un código</h3>
                    <form method="POST" action="{{ route('admin.2fa.enable') }}" class="mt-2 flex flex-wrap items-start gap-2">
                        @csrf
                        <div>
                            <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456"
                                   class="snow-input w-32 text-center font-mono text-sm tracking-[0.3em]" autofocus autocomplete="one-time-code">
                            @error('code')
                                <p class="mt-1 text-[10px] text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="snow-btn-primary">Activar 2FA</button>
                    </form>
                </div>
            @else
                <div class="snow-card rounded-xl border border-slate-200/90 bg-white p-4 shadow-hope-card">
                    <a href="{{ route('admin.2fa.show') }}" class="snow-btn-primary inline-block">Comenzar configuración</a>
                </div>
            @endif
        @else
            @if(session('admin_2fa_recovery'))
                <div class="snow-card rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-hope-card">
                    <h3 class="text-xs font-semibold text-amber-900">Códigos de recuperación — se muestran una sola vez</h3>
                    <div class="mt-2 grid grid-cols-2 gap-1 font-mono text-[11px] text-amber-900 sm:grid-cols-4">
                        @foreach(session('admin_2fa_recovery') as $code)
                            <span class="rounded bg-white/70 px-2 py-1 text-center">{{ $code }}</span>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[10px] text-amber-800">Guárdalos en un lugar seguro. Cada uno funciona una sola vez si pierdes tu dispositivo.</p>
                </div>
            @endif

            <div class="snow-card rounded-xl border border-slate-200/90 bg-white p-4 shadow-hope-card">
                <h3 class="text-xs font-semibold text-slate-900">Desactivar 2FA</h3>
                <form method="POST" action="{{ route('admin.2fa.disable') }}" class="mt-2 flex flex-wrap items-start gap-2">
                    @csrf
                    <div>
                        <input type="text" name="code" placeholder="Código actual o de recuperación"
                               class="snow-input w-56 text-xs" autocomplete="one-time-code">
                        @error('code')
                            <p class="mt-1 text-[10px] text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100">Desactivar</button>
                </form>
            </div>
        @endif
    </div>
</x-admin.layouts.app>
