<x-admin.layouts.app title="Generar lote">
    @php
        $typeOld = old('coupon_type', 'QRCODE');
        $err = fn (string $f) => $errors->has($f);
    @endphp
    <form method="POST" action="{{ route('admin.coupon-batches.store') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-3"
          x-data="{
              type: @js($typeOld),
              quantity: {{ (int) old('quantity', 100) }},
              amount: {{ (int) old('amount', 0) }},
              expire: @js(old('expire_date', $defaultExpire)),
              prefix: @js(old('prefix', '')),
              money(v) { return '$' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
              get total() { return (Number(this.quantity) || 0) * (Number(this.amount) || 0); },
              get example() { return (this.prefix || '') + '48151623…'; },
              get days() { if (!this.expire) return null; return Math.round((new Date(this.expire) - new Date(@js($tomorrow))) / 864e5); },
          }">
        @csrf

        <div class="space-y-4 lg:col-span-2">
            {{-- 1. Identificación --}}
            <section class="snow-card">
                <header class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-medium text-slate-800">1. Identificación del lote</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Cómo se reconocerá el lote en listas, reportes y auditoría.</p>
                </header>
                <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="snow-label" for="name">Nombre del lote <span class="text-red-600">*</span></label>
                        <input id="name" name="name" type="text" maxlength="150" value="{{ old('name') }}" class="snow-input" placeholder="Ej.: Navidad 2026 — Empresa X" required autofocus>
                        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <fieldset class="md:col-span-2">
                        <legend class="snow-label">Tipo de cupón <span class="text-red-600">*</span></legend>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm" :class="type === 'QRCODE' ? 'border-primary-600 bg-primary-50' : 'border-slate-200'">
                                <input type="radio" name="coupon_type" value="QRCODE" x-model="type" class="mt-1">
                                <span><strong class="block text-slate-800">QR</strong><span class="text-xs text-slate-500">Número + token aleatorio de 128 bits. El token solo existe en el CSV de la generación.</span></span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm" :class="type === 'CODBAR' ? 'border-primary-600 bg-primary-50' : 'border-slate-200'">
                                <input type="radio" name="coupon_type" value="CODBAR" x-model="type" class="mt-1">
                                <span><strong class="block text-slate-800">Código de barras</strong><span class="text-xs text-slate-500">Solo número aleatorio; se cobra con el número.</span></span>
                            </label>
                        </div>
                        @error('coupon_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </fieldset>

                    <div>
                        <label class="snow-label" for="prefix">Prefijo del número</label>
                        <select id="prefix" name="prefix" class="snow-input" x-model="prefix">
                            <option value="">Sin prefijo</option>
                            @foreach($prefixes as $p)
                                <option value="{{ $p->prefix }}" @selected(old('prefix') === $p->prefix)>{{ $p->prefix }}@if($p->description) — {{ $p->description }}@endif</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500">
                            Sale del catálogo de
                            @can('coupon_prefixes.view')<a href="{{ route('admin.screens.index', 'coupon-prefixes') }}" class="text-primary-600 hover:underline">prefijos</a>@else prefijos @endcan.
                            @if($prefixes->isEmpty()) <strong>Aún no hay prefijos activos.</strong>@endif
                        </p>
                        @error('prefix')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="snow-label" for="quantity">Cantidad de cupones <span class="text-red-600">*</span></label>
                        <input id="quantity" name="quantity" type="number" inputmode="numeric" min="1" max="{{ $max }}" step="1" x-model.number="quantity" class="snow-input" required>
                        <p class="mt-1 text-xs text-slate-500">De 1 a {{ number_format($max) }} por lote.</p>
                        @error('quantity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            {{-- 2. Valor y vigencia --}}
            <section class="snow-card">
                <header class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-medium text-slate-800">2. Valor y vigencia</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Todos los cupones del lote nacen con el mismo monto y la misma fecha de vencimiento.</p>
                </header>
                <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
                    <div>
                        <label class="snow-label" for="amount">Monto de cada cupón (entero) <span class="text-red-600">*</span></label>
                        <input id="amount" name="amount" type="number" inputmode="numeric" min="1" max="{{ $maxAmount }}" step="1" pattern="[0-9]*"
                               x-model.number="amount" x-on:keydown="if (['.', ',', 'e', 'E', '-', '+'].includes($event.key)) $event.preventDefault()"
                               class="snow-input" required>
                        <p class="mt-1 text-xs text-slate-500">Solo números enteros, sin centavos (hasta {{ number_format($maxAmount) }}).</p>
                        @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="snow-label" for="expire_date">Fecha de vencimiento</label>
                        <input id="expire_date" name="expire_date" type="date" min="{{ $tomorrow }}" x-model="expire" class="snow-input">
                        <p class="mt-1 text-xs text-slate-500">Por defecto, un año contado desde mañana. Déjala vacía si no vencen.</p>
                        @error('expire_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            {{-- 3. Notas --}}
            <section class="snow-card">
                <header class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-medium text-slate-800">3. Notas y comentarios</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Contexto para quien asigne o active el lote: campaña, cliente, condiciones, autorizaciones.</p>
                </header>
                <div class="p-5">
                    <label class="snow-label" for="notes">Comentarios (opcional)</label>
                    <textarea id="notes" name="notes" rows="7" maxlength="2000" class="snow-input" x-data="{ n: {{ mb_strlen((string) old('notes')) }} }" x-on:input="n = $el.value.length" placeholder="Escribe aquí los detalles del lote…">{{ old('notes') }}</textarea>
                    <p class="mt-1 text-right text-xs text-slate-500">Hasta 2,000 caracteres</p>
                    @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </section>
        </div>

        {{-- Resumen --}}
        <aside class="lg:col-span-1">
            <div class="snow-card lg:sticky lg:top-20">
                <header class="border-b border-slate-200 px-5 py-3">
                    <h2 class="text-base font-medium text-slate-800">Resumen</h2>
                </header>
                <dl class="space-y-3 p-5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Tipo</dt><dd class="font-medium text-slate-800" x-text="type === 'QRCODE' ? 'QR' : 'Código de barras'"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Cantidad</dt><dd class="font-medium tabular-nums text-slate-800" x-text="Number(quantity || 0).toLocaleString('en-US')"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Monto por cupón</dt><dd class="font-medium tabular-nums text-slate-800" x-text="money(amount)"></dd></div>
                    <div class="flex justify-between gap-3 border-t border-slate-200 pt-3"><dt class="text-slate-500">Valor total del lote</dt><dd class="text-base font-semibold tabular-nums text-slate-900" x-text="money(total)"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Vence</dt><dd class="text-right font-medium text-slate-800"><span x-text="expire || 'No vence'"></span><span class="block text-xs font-normal text-slate-500" x-show="days !== null" x-text="days + ' días desde mañana'"></span></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Ejemplo de número</dt><dd class="font-mono text-xs text-slate-800" x-text="example"></dd></div>
                </dl>
                <div class="border-t border-slate-200 px-5 py-4 text-xs text-slate-500">
                    <ul class="list-disc space-y-1 pl-4">
                        <li>Los cupones nacen <strong>sin activar</strong> y sin cliente.</li>
                        <li>Se activan aparte, por lote completo o por selección.</li>
                        <li>El CSV con los números{{ '' }}<span x-show="type === 'QRCODE'"> y tokens</span> se descarga <strong>una sola vez</strong> y vence a las {{ config('coupons.export_ttl_hours') }} h.</li>
                        <li>Todo queda registrado en la auditoría.</li>
                    </ul>
                </div>
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 px-5 py-4">
                    <button type="submit" class="snow-btn-primary">Generar lote</button>
                    <a href="{{ route('admin.screens.index', 'coupon-batches') }}" class="snow-btn-secondary">Cancelar</a>
                </div>
            </div>
        </aside>
    </form>
</x-admin.layouts.app>
