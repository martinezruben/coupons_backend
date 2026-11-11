{{-- Modal código por localidad; requiere x-data="locationPairingToken()" en un ancestro --}}
<div
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/45 p-4 backdrop-blur-[2px]"
    @click.self="closeModal()"
    @keydown.escape.window="closeModal()"
    role="dialog"
    aria-modal="true"
    aria-labelledby="location-pairing-title"
>
    <div class="w-full max-w-[340px] overflow-hidden rounded-[14px] bg-[#f2f2f7] shadow-2xl ring-1 ring-black/5" @click.stop>
        <div class="px-5 pt-5 pb-4 text-center">
            <h2 id="location-pairing-title" class="text-[17px] font-semibold leading-snug tracking-tight text-slate-900">
                Código de verificación de dispositivo
            </h2>
            <p class="mt-2 text-[13px] leading-relaxed text-slate-600">
                Introduce este código en el dispositivo para enrolarlo en esta localidad.
            </p>
            <p x-show="locationName" class="mt-1 text-[13px] font-semibold text-slate-800" x-text="'Localidad: ' + locationName"></p>

            <div class="relative mt-6 min-h-[3.5rem] flex items-center justify-center">
                <div x-show="code" class="flex items-baseline justify-center gap-[0.65rem] text-[34px] font-normal tabular-nums tracking-[0.12em] text-slate-900">
                    <span x-text="code.slice(0, 3)"></span>
                    <span class="w-2"></span>
                    <span x-text="code.slice(3, 6)"></span>
                </div>
                <div x-show="!code && !loading" class="text-sm font-medium text-slate-500">
                    Sin código activo
                </div>
                <div
                    x-show="loading"
                    x-transition.opacity
                    class="absolute inset-0 flex items-center justify-center rounded-lg bg-[#f2f2f7]/85"
                >
                    <span class="text-sm text-slate-500" x-text="code ? 'Actualizando…' : 'Generando…'"></span>
                </div>
            </div>

            <p class="mt-3 text-[13px] font-medium tabular-nums text-slate-700">
                <span class="text-slate-500">Tiempo restante:</span>
                <span x-text="remainingLabel"></span>
            </p>
        </div>

        <div class="h-px bg-black/[0.08]"></div>

        <div class="grid grid-cols-2 divide-x divide-black/[0.08] bg-[#f2f2f7]">
            <button
                type="button"
                class="min-h-[44px] px-2 text-[17px] font-normal text-slate-900 transition hover:bg-black/[0.04] disabled:opacity-40"
                :disabled="loading"
                @click="regenerate()"
            >
                Regenerar
            </button>
            <button
                type="button"
                class="min-h-[44px] px-2 text-[17px] font-semibold text-[#007aff] transition hover:bg-black/[0.04]"
                @click="closeModal()"
            >
                Cerrar
            </button>
        </div>
    </div>
</div>
