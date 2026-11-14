<x-admin.layouts.app title="Generar lote">
    <div class="snow-card">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-medium text-slate-800">Nuevo lote de cupones</h2>
            <p class="mt-0.5 text-sm text-slate-500">
                Los cupones nacen <strong>sin activar</strong>, con número aleatorio y, los QR, con un token aleatorio. El token solo existe en el archivo CSV
                que se genera con el lote: se descarga <strong>una sola vez</strong> (vence a las {{ config('coupons.export_ttl_hours') }} h) y no se puede volver a ver en ninguna pantalla.
            </p>
        </div>
        <form method="POST" action="{{ route('admin.coupon-batches.store') }}" class="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
            @csrf
            <div class="md:col-span-2">
                <label class="snow-label" for="name">Nombre del lote</label>
                <input id="name" name="name" type="text" maxlength="150" value="{{ old('name') }}" class="snow-input" placeholder="Ej.: Navidad 2026 — Empresa X" required>
                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="snow-label" for="coupon_type">Tipo de cupón</label>
                <select id="coupon_type" name="coupon_type" class="snow-input">
                    <option value="QRCODE" @selected(old('coupon_type', 'QRCODE') === 'QRCODE')>QR (número + token)</option>
                    <option value="CODBAR" @selected(old('coupon_type') === 'CODBAR')>Código de barras (solo número)</option>
                </select>
                @error('coupon_type')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="snow-label" for="quantity">Cantidad (1 a {{ number_format($max) }})</label>
                <input id="quantity" name="quantity" type="number" min="1" max="{{ $max }}" value="{{ old('quantity', 100) }}" class="snow-input" required>
                @error('quantity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="snow-label" for="amount">Monto de cada cupón</label>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="snow-input" required>
                @error('amount')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="snow-label" for="expire_date">Vence (opcional)</label>
                <input id="expire_date" name="expire_date" type="date" value="{{ old('expire_date') }}" class="snow-input">
                @error('expire_date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="snow-label" for="prefix">Prefijo de los números (opcional, hasta 6 caracteres)</label>
                <input id="prefix" name="prefix" type="text" maxlength="6" value="{{ old('prefix') }}" class="snow-input uppercase">
                @error('prefix')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="snow-label" for="notes">Notas (opcional)</label>
                <input id="notes" name="notes" type="text" maxlength="500" value="{{ old('notes') }}" class="snow-input">
                @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="flex flex-wrap items-center gap-2 md:col-span-2">
                <button type="submit" class="snow-btn-primary">Generar lote</button>
                <a href="{{ route('admin.screens.index', 'coupon-batches') }}" class="snow-btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</x-admin.layouts.app>
