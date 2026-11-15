<x-admin.layouts.app :title="$rule ? 'Editar regla de recarga' : 'Nueva regla de recarga'">
    @php
        $days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $oldDays = old('weekdays', $rule?->weekdayList() ?? []);
        $oldLocs = old('location_ids', $rule?->locationIdList() ?? []);
        $money = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $init = [
            'name' => old('name', $rule?->name ?? ''),
            'coupon_type' => old('coupon_type', $rule?->coupon_type ?? ''),
            'active' => (bool) old('is_active', $rule?->is_active ?? true),
            'useLoc' => count($oldLocs) > 0,
            'locs' => array_values(array_map('strval', $oldLocs)),
            'useDays' => count($oldDays) > 0,
            'days' => array_values(array_map('intval', $oldDays)),
            'useTime' => (bool) old('time_from', $rule?->time_from),
            'from' => old('time_from', $rule?->time_from ?? '08:00'),
            'to' => old('time_to', $rule?->time_to ?? '18:00'),
            'usePer' => old('max_per_topup', $rule?->max_per_topup) !== null && old('max_per_topup', $rule?->max_per_topup) !== '',
            'per' => $money(old('max_per_topup', $rule?->max_per_topup)),
            'useDaily' => old('max_daily_amount', $rule?->max_daily_amount) !== null && old('max_daily_amount', $rule?->max_daily_amount) !== '',
            'daily' => $money(old('max_daily_amount', $rule?->max_daily_amount)),
            'locNames' => $locations->pluck('name', 'id'),
            'dayNames' => $days,
        ];
        $card = 'rounded-lg border p-4 transition';
    @endphp
    <form method="POST" action="{{ $rule ? route('admin.screens.update', ['topup-rules', $rule->id]) : route('admin.screens.store', 'topup-rules') }}"
          class="grid grid-cols-1 gap-4 lg:grid-cols-3" x-data="topupRule(@js($init))">
        @csrf
        @if($rule)@method('PUT')@endif

        <div class="space-y-4 lg:col-span-2">
            <section class="snow-card">
                <header class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-medium text-slate-800">1. ¿Qué regla es?</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Un nombre claro y a qué cupones aplica.</p>
                </header>
                <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="snow-label" for="name">Nombre de la regla <span class="text-red-600">*</span></label>
                        <input id="name" name="name" type="text" maxlength="100" x-model="name" class="snow-input" placeholder="Ej.: Recargas en caja, lunes a viernes" required autofocus>
                        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="snow-label" for="coupon_type">Aplica a</label>
                        <select id="coupon_type" name="coupon_type" class="snow-input" x-model="coupon_type">
                            <option value="">Cupones QR y de barras</option>
                            <option value="QRCODE">Solo cupones QR</option>
                            <option value="CODBAR">Solo cupones de barras</option>
                        </select>
                        @error('coupon_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex items-end">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" x-model="active" class="h-4 w-4 rounded border-slate-300">
                            Regla activa (si la apagas, deja de aplicarse)
                        </label>
                    </div>
                </div>
            </section>

            <section class="snow-card">
                <header class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-medium text-slate-800">2. Condiciones</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Activa las que necesites y súmalas: la recarga se permite <strong>solo si se cumplen todas</strong>.</p>
                    @error('conditions')<p class="mt-2 rounded border border-red-200 bg-red-50 p-2 text-sm text-red-700">{{ $message }}</p>@enderror
                </header>
                <div class="space-y-3 p-5">
                    {{-- Localidades --}}
                    <div class="{{ $card }}" :class="useLoc ? 'border-primary-600 bg-primary-50/40' : 'border-slate-200'">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" x-model="useLoc" class="mt-1 h-4 w-4 rounded border-slate-300">
                            <span><strong class="block text-slate-800">Solo en ciertas localidades</strong><span class="text-xs text-slate-500">La recarga solo se acepta desde dispositivos de las localidades elegidas. Las recargas hechas desde el panel no tienen localidad y no se evalúan aquí.</span></span>
                        </label>
                        <div x-show="useLoc" x-cloak class="mt-3 grid grid-cols-1 gap-1 sm:grid-cols-2">
                            @forelse($locations as $l)
                                <label class="flex cursor-pointer items-center gap-2 rounded border border-slate-200 bg-white px-2 py-1.5 text-sm">
                                    <input type="checkbox" name="location_ids[]" value="{{ $l->id }}" x-model="locs" :disabled="!useLoc" class="h-4 w-4 rounded border-slate-300"> {{ $l->name }}
                                </label>
                            @empty
                                <p class="text-sm text-slate-500">Aún no hay localidades creadas.</p>
                            @endforelse
                        </div>
                        @error('location_ids')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    {{-- Días --}}
                    <div class="{{ $card }}" :class="useDays ? 'border-primary-600 bg-primary-50/40' : 'border-slate-200'">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" x-model="useDays" class="mt-1 h-4 w-4 rounded border-slate-300">
                            <span><strong class="block text-slate-800">Solo ciertos días de la semana</strong><span class="text-xs text-slate-500">Fuera de esos días no se puede recargar.</span></span>
                        </label>
                        <div x-show="useDays" x-cloak class="mt-3 flex flex-wrap gap-2">
                            @foreach($days as $n => $label)
                                <label class="cursor-pointer rounded-full border px-3 py-1 text-sm" :class="days.includes({{ $n }}) ? 'border-primary-600 bg-primary-600 text-white' : 'border-slate-300 bg-white text-slate-700'">
                                    <input type="checkbox" name="weekdays[]" value="{{ $n }}" x-model.number="days" :disabled="!useDays" class="sr-only"> {{ $label }}
                                </label>
                            @endforeach
                            <span class="flex gap-2 text-xs">
                                <button type="button" class="text-primary-600 hover:underline" x-on:click="days = [1,2,3,4,5]">Lun–Vie</button>
                                <button type="button" class="text-primary-600 hover:underline" x-on:click="days = [6,7]">Fin de semana</button>
                                <button type="button" class="text-primary-600 hover:underline" x-on:click="days = [1,2,3,4,5,6,7]">Todos</button>
                            </span>
                        </div>
                        @error('weekdays')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    {{-- Horario --}}
                    <div class="{{ $card }}" :class="useTime ? 'border-primary-600 bg-primary-50/40' : 'border-slate-200'">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" x-model="useTime" class="mt-1 h-4 w-4 rounded border-slate-300">
                            <span><strong class="block text-slate-800">Solo en un horario</strong><span class="text-xs text-slate-500">Si el fin es menor que el inicio, el horario cruza la medianoche (ej. 22:00 a 06:00).</span></span>
                        </label>
                        <div x-show="useTime" x-cloak class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                            <label for="time_from" class="text-slate-600">De</label>
                            <input id="time_from" name="time_from" type="time" x-model="from" :disabled="!useTime" class="snow-input w-32">
                            <label for="time_to" class="text-slate-600">a</label>
                            <input id="time_to" name="time_to" type="time" x-model="to" :disabled="!useTime" class="snow-input w-32">
                        </div>
                        @error('time_from')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        @error('time_to')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    {{-- Tope por recarga --}}
                    <div class="{{ $card }}" :class="usePer ? 'border-primary-600 bg-primary-50/40' : 'border-slate-200'">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" x-model="usePer" class="mt-1 h-4 w-4 rounded border-slate-300">
                            <span><strong class="block text-slate-800">Tope por cada recarga</strong><span class="text-xs text-slate-500">Monto máximo de una sola recarga.</span></span>
                        </label>
                        <div x-show="usePer" x-cloak class="mt-3 flex items-center gap-2 text-sm">
                            <span class="text-slate-600">Hasta $</span>
                            <input id="max_per_topup" name="max_per_topup" type="number" min="0.01" step="0.01" x-model="per" :disabled="!usePer" class="snow-input w-40">
                        </div>
                        @error('max_per_topup')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    {{-- Tope por día --}}
                    <div class="{{ $card }}" :class="useDaily ? 'border-primary-600 bg-primary-50/40' : 'border-slate-200'">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" x-model="useDaily" class="mt-1 h-4 w-4 rounded border-slate-300">
                            <span><strong class="block text-slate-800">Tope por día y por cupón</strong><span class="text-xs text-slate-500">Suma de todo lo que se recarga a un mismo cupón en el día.</span></span>
                        </label>
                        <div x-show="useDaily" x-cloak class="mt-3 flex items-center gap-2 text-sm">
                            <span class="text-slate-600">Hasta $</span>
                            <input id="max_daily_amount" name="max_daily_amount" type="number" min="0.01" step="0.01" x-model="daily" :disabled="!useDaily" class="snow-input w-40">
                        </div>
                        @error('max_daily_amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>
        </div>

        <aside class="lg:col-span-1">
            <div class="snow-card lg:sticky lg:top-20">
                <header class="border-b border-slate-200 px-5 py-3"><h2 class="text-base font-medium text-slate-800">Cómo quedará</h2></header>
                <div class="space-y-3 p-5 text-sm">
                    <p class="font-medium text-slate-900" x-text="name || 'Regla sin nombre'"></p>
                    <p class="text-slate-600">Aplica a <strong x-text="coupon_type === 'QRCODE' ? 'cupones QR' : (coupon_type === 'CODBAR' ? 'cupones de barras' : 'cupones QR y de barras')"></strong>.</p>
                    <template x-if="conditions.length === 0"><p class="rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">Todavía no hay condiciones. Activa al menos una.</p></template>
                    <template x-if="conditions.length > 0">
                        <div>
                            <p class="text-slate-600">Se permitirá recargar <strong>solo si</strong>:</p>
                            <ul class="mt-1 list-disc space-y-1 pl-5 text-slate-800">
                                <template x-for="c in conditions" :key="c"><li x-text="c"></li></template>
                            </ul>
                        </div>
                    </template>
                    <p class="text-xs" :class="active ? 'text-green-700' : 'text-slate-500'" x-text="active ? 'La regla estará activa.' : 'La regla quedará apagada.'"></p>
                </div>
                <div class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">
                    Con varias reglas activas, basta con que <strong>una</strong> se cumpla completa. Sin reglas, no hay restricciones.
                </div>
                <div class="flex flex-wrap gap-2 border-t border-slate-200 px-5 py-4">
                    <button type="submit" class="snow-btn-primary">{{ $rule ? 'Guardar cambios' : 'Crear regla' }}</button>
                    <a href="{{ route('admin.screens.index', 'topup-rules') }}" class="snow-btn-secondary">Cancelar</a>
                </div>
            </div>
        </aside>
    </form>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('topupRule', (i) => ({
                ...i,
                get conditions() {
                    const out = [];
                    if (this.useLoc && this.locs.length) out.push('la recarga se hace en ' + this.locs.map(id => this.locNames[id]).filter(Boolean).join(', '));
                    if (this.useDays && this.days.length) out.push('es ' + [...this.days].sort().map(d => this.dayNames[d].toLowerCase()).join(', '));
                    if (this.useTime && this.from && this.to) out.push('es entre las ' + this.from + ' y las ' + this.to);
                    if (this.usePer && this.per) out.push('la recarga no pasa de $' + this.per);
                    if (this.useDaily && this.daily) out.push('lo recargado al cupón en el día no pasa de $' + this.daily);
                    return out;
                },
            }));
        });
    </script>
</x-admin.layouts.app>
