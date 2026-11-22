{{-- Pase de enrolamiento por localidad (diseño tipo cupón). Requiere x-data="locationPairingToken()" en un ancestro --}}
<div
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/55 p-4 backdrop-blur-[2px]"
    @click.self="closeModal()"
    @keydown.escape.window="closeModal()"
    role="dialog"
    aria-modal="true"
    aria-labelledby="location-pairing-title"
>
    {{-- El drop-shadow va fuera: la máscara que recorta las muescas también recortaría un box-shadow --}}
    <div class="w-full max-w-[380px] drop-shadow-2xl" @click.stop>
        <div
            class="relative overflow-hidden rounded-[18px] bg-[#fbf4e4] text-[#1f2a37]"
            style="--stub: 176px; --notch: 13px;
                   -webkit-mask: radial-gradient(circle var(--notch) at 0 calc(100% - var(--stub)), #0000 97%, #000), radial-gradient(circle var(--notch) at 100% calc(100% - var(--stub)), #0000 97%, #000);
                   -webkit-mask-composite: source-in;
                   mask: radial-gradient(circle var(--notch) at 0 calc(100% - var(--stub)), #0000 97%, #000), radial-gradient(circle var(--notch) at 100% calc(100% - var(--stub)), #0000 97%, #000);
                   mask-composite: intersect;"
        >
            {{-- Cinta superior --}}
            <div class="flex items-center justify-between bg-[#0f4c47] px-5 py-2.5 text-[11px] font-bold uppercase tracking-[0.18em] text-[#fbf4e4]">
                <span id="location-pairing-title">Pase de enrolamiento</span>
                <span class="rounded-full border border-[#fbf4e4]/50 px-2 py-0.5 text-[10px] tracking-[0.12em]">Un solo uso</span>
            </div>

            {{-- Cuerpo: primero la localidad, luego el código --}}
            <div class="px-5 pb-5 pt-4">
                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-[#0f4c47]/70">Válido para</p>
                <p class="mt-0.5 flex items-center gap-2 text-[26px] font-extrabold leading-tight tracking-tight">
                    <svg class="h-6 w-6 shrink-0 text-[#c2410c]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                    <span class="min-w-0 break-words" x-text="locationName || 'Localidad'"></span>
                </p>

                {{-- Pantalla de calculadora: un recuadro por dígito --}}
                <div class="relative mt-4 rounded-xl bg-[#111827] p-3 shadow-[inset_0_2px_6px_rgba(0,0,0,0.6)]">
                    <div class="flex items-center justify-center gap-1.5" role="img" :aria-label="code ? 'Código ' + code.split('').join(' ') : 'Sin código activo'">
                        <template x-for="i in 6" :key="i">
                            <span class="contents">
                                <span
                                    class="flex h-[52px] w-[44px] items-center justify-center rounded-md bg-[#c8dfb8] font-mono text-[30px] font-bold tabular-nums text-[#14231a] shadow-[inset_0_-3px_0_rgba(20,35,26,0.18),inset_0_1px_0_rgba(255,255,255,0.55)]"
                                    :class="code ? '' : 'opacity-40'"
                                    x-text="code ? code[i - 1] : '·'"
                                ></span>
                                <span x-show="i === 3" class="mx-0.5 h-1 w-2 rounded-full bg-[#c8dfb8]/70" aria-hidden="true"></span>
                            </span>
                        </template>
                    </div>
                    <div x-show="loading" x-transition.opacity class="absolute inset-0 flex items-center justify-center rounded-xl bg-[#111827]/85">
                        <span class="text-sm font-medium text-[#c8dfb8]" x-text="code ? 'Actualizando…' : 'Generando…'"></span>
                    </div>
                </div>
                <p x-show="!code && !loading" class="mt-2 text-center text-xs font-medium text-[#b45309]">Sin código activo: pulsa «Regenerar».</p>

                <p class="mt-3 text-[13px] leading-snug text-[#1f2a37]/75">
                    Escribe este código en el dispositivo para enrolarlo en esta localidad.
                </p>
            </div>

            {{-- Línea de corte (las muescas laterales las hace la máscara) --}}
            <div class="absolute inset-x-5 border-t-2 border-dashed border-[#0f4c47]/35" style="bottom: calc(var(--stub));" aria-hidden="true"></div>

            {{-- Talón: tiempo restante y acciones --}}
            <div class="flex flex-col justify-between bg-[#f3e9d0] px-5 pb-4 pt-4" style="height: var(--stub);">
                <div>
                    <div class="flex items-end justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-[#0f4c47]/70">Vence en</span>
                        <span
                            class="font-mono text-[28px] font-bold leading-none tabular-nums"
                            :class="code && remainingSeconds < 60 ? 'text-[#b91c1c]' : 'text-[#1f2a37]'"
                            x-text="remainingLabel"
                            aria-live="off"
                        ></span>
                    </div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-[#0f4c47]/15" aria-hidden="true">
                        <div
                            class="h-full rounded-full transition-[width] duration-1000 ease-linear"
                            :class="remainingSeconds < 60 ? 'bg-[#b91c1c]' : 'bg-[#0f4c47]'"
                            :style="'width:' + (code ? Math.round(remainingSeconds / 9) : 0) + '%'"
                        ></div>
                    </div>
                </div>

                <div class="grid grid-cols-[1fr_auto] items-center gap-3">
                    <button
                        type="button"
                        class="min-h-[44px] rounded-lg border-2 border-[#0f4c47] px-3 text-sm font-bold uppercase tracking-[0.08em] text-[#0f4c47] transition hover:bg-[#0f4c47]/10 disabled:opacity-40"
                        :disabled="loading"
                        @click="regenerate()"
                    >
                        Regenerar
                    </button>
                    <button
                        type="button"
                        class="min-h-[44px] rounded-lg bg-[#0f4c47] px-5 text-sm font-bold uppercase tracking-[0.08em] text-[#fbf4e4] transition hover:bg-[#0b3a36]"
                        @click="closeModal()"
                    >
                        Cerrar
                    </button>
                </div>

                {{-- Código de barras decorativo --}}
                <div
                    class="h-3 rounded-[2px] opacity-70"
                    style="background: repeating-linear-gradient(90deg, #1f2a37 0 2px, transparent 2px 4px, #1f2a37 4px 5px, transparent 5px 8px, #1f2a37 8px 11px, transparent 11px 13px);"
                    aria-hidden="true"
                ></div>
            </div>
        </div>
    </div>
</div>
