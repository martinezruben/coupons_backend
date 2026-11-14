@php
    $modeLabels = ['now' => 'Activar ahora (el cupón queda vigente de inmediato)', 'scheduled' => 'Activar en una fecha y hora', 'assign' => 'Solo asignar al cliente (se activa después)'];
    $selectionLabels = ['all' => 'Todos los elegibles del lote', 'range' => 'Un rango de la secuencia', 'quantity' => 'Una cantidad (los primeros N)', 'list' => 'Una lista de números'];
    if (! $canAll) {
        unset($selectionLabels['all']);
    }
    $selection = old('selection_type', array_key_first($selectionLabels));
    $mode = old('mode', $modes[0] ?? 'now');
@endphp
<x-admin.layouts.app :title="'Asignar y activar — '.$batch->code">
    <form method="POST" action="{{ route('admin.coupon-batches.activate', $batch) }}" enctype="multipart/form-data"
          x-data="{ selection: '{{ $selection }}', mode: '{{ $mode }}' }" class="snow-card">
        @csrf
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-medium text-slate-800">{{ $batch->name }}</h2>
            <p class="mt-0.5 text-sm text-slate-500">
                Elige qué cupones del lote, para qué cliente y cuándo. Verás un resumen antes de aplicar.
                Solo se asignan los cupones <em>generados</em>; se activan los <em>generados</em> o los <em>asignados al mismo cliente</em>.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 p-5 lg:grid-cols-2">
            <div class="space-y-4">
                <h3 class="subheader">1. Selección</h3>
                <div class="space-y-2">
                    @foreach($selectionLabels as $key => $label)
                        <label class="flex items-center gap-2">
                            <input type="radio" name="selection_type" value="{{ $key }}" x-model="selection"> <span>{{ $label }}</span>
                        </label>
                    @endforeach
                    @error('selection_type')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div x-show="selection === 'range'" x-cloak class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="snow-label" for="from">Desde (n.º de secuencia)</label>
                        <input id="from" name="from" type="number" min="1" max="{{ $batch->quantity }}" value="{{ old('from') }}" class="snow-input">
                        @error('from')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="snow-label" for="to">Hasta</label>
                        <input id="to" name="to" type="number" min="1" max="{{ $batch->quantity }}" value="{{ old('to') }}" class="snow-input">
                        @error('to')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div x-show="selection === 'quantity'" x-cloak>
                    <label class="snow-label" for="quantity">Cantidad</label>
                    <input id="quantity" name="quantity" type="number" min="1" max="{{ config('coupons.max_activation_size') }}" value="{{ old('quantity') }}" class="snow-input">
                    @error('quantity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div x-show="selection === 'list'" x-cloak class="space-y-2">
                    <label class="snow-label" for="numbers">Números de cupón (uno por línea, o separados por coma)</label>
                    <textarea id="numbers" name="numbers" rows="6" class="snow-input font-mono">{{ old('numbers') }}</textarea>
                    <label class="snow-label" for="numbers_file">…o un archivo .csv / .txt con los números</label>
                    <input id="numbers_file" name="numbers_file" type="file" accept=".csv,.txt" class="snow-input">
                    @error('numbers')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('numbers_file')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="space-y-4">
                <h3 class="subheader">2. Cliente y momento</h3>
                <div>
                    <label class="snow-label" for="customer_id">Cliente</label>
                    <select id="customer_id" name="customer_id" class="snow-input" required>
                        <option value="">— Seleccionar —</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" @selected(old('customer_id') === $customer->id)>{{ $customer->name }}@if($customer->document) · {{ $customer->document }}@endif</option>
                        @endforeach
                    </select>
                    @error('customer_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-slate-500">¿No está? <a href="{{ route('admin.screens.create', 'customers') }}" class="text-primary-600 hover:underline">Registra un cliente</a>.</p>
                </div>
                <div class="space-y-2">
                    @foreach(collect($modeLabels)->only($modes) as $key => $label)
                        <label class="flex items-center gap-2">
                            <input type="radio" name="mode" value="{{ $key }}" x-model="mode"> <span>{{ $label }}</span>
                        </label>
                    @endforeach
                    @error('mode')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div x-show="mode === 'scheduled'" x-cloak>
                    <label class="snow-label" for="activate_at">Fecha y hora de activación</label>
                    <input id="activate_at" name="activate_at" type="datetime-local" value="{{ old('activate_at') }}" class="snow-input">
                    @error('activate_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="snow-label" for="reason">Motivo o referencia (opcional)</label>
                    <input id="reason" name="reason" type="text" maxlength="255" value="{{ old('reason') }}" class="snow-input" placeholder="Ej.: pedido 1234">
                    @error('reason')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-100 px-5 py-3">
            <button type="submit" class="snow-btn-primary">Ver resumen</button>
            <a href="{{ route('admin.coupon-batches.show', $batch) }}" class="snow-btn-secondary">Cancelar</a>
        </div>
    </form>
</x-admin.layouts.app>
