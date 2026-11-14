@php
    $isQr = $type === 'qr';
    $title = $isQr ? 'Emitir cupones QR' : 'Emitir cupones de barras';
    $csv = null;
    if ($issued) {
        $lines = [$isQr ? 'numero,token,contenido_qr' : 'numero'];
        foreach ($issued as $row) {
            $lines[] = $isQr ? implode(',', [$row['coupon_number'], $row['coupon_token'], $row['qr_payload']]) : $row['coupon_number'];
        }
        $csv = 'data:text/csv;charset=utf-8,'.rawurlencode(implode("\n", $lines)."\n");
    }
@endphp

<x-admin.layouts.app :title="$title">
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-100 bg-slate-50/80 px-3 py-2 sm:px-4">
            <p class="mt-0.5 text-xs text-slate-500">
                Los números se generan al azar. @if($isQr) El token de cada cupón QR solo se muestra una vez, en esta pantalla. @endif
            </p>
        </div>

        @if($issued)
            <div class="space-y-3 p-3 sm:p-4">
                <p class="text-xs font-semibold text-emerald-700">
                    Se emitieron {{ count($issued) }} cupones de ${{ number_format((float) $amount, 2) }}.
                    @if($isQr) Descarga el archivo ahora: los tokens no se podrán ver después. @endif
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ $csv }}" download="cupones-{{ $type }}-{{ now()->format('Ymd-His') }}.csv" class="snow-btn-primary">Descargar CSV</a>
                    <a href="{{ route('admin.screens.index', $screen) }}" class="snow-btn-secondary">Volver al listado</a>
                </div>
                <div class="max-h-96 overflow-auto rounded-lg border border-slate-200">
                    <table class="w-full border-collapse text-left text-xs">
                        <thead class="bg-slate-50"><tr><th class="px-2 py-1">Número</th>@if($isQr)<th class="px-2 py-1">Contenido del QR</th>@endif</tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($issued as $row)
                                <tr>
                                    <td class="px-2 py-1 font-mono">{{ $row['coupon_number'] }}</td>
                                    @if($isQr)<td class="px-2 py-1 font-mono break-all">{{ $row['qr_payload'] }}</td>@endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('admin.coupons.issue.store', $type) }}" class="grid grid-cols-1 gap-3 px-4 py-4 md:grid-cols-2">
                @csrf
                <div>
                    <label class="snow-label" for="quantity">Cantidad (máx. 500)</label>
                    <input id="quantity" name="quantity" type="number" min="1" max="500" value="{{ old('quantity', 1) }}" class="snow-input" required>
                    @error('quantity')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="snow-label" for="amount">Monto de cada cupón</label>
                    <input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="snow-input" required>
                    @error('amount')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="snow-label" for="expire_date">Vence (opcional)</label>
                    <input id="expire_date" name="expire_date" type="date" value="{{ old('expire_date') }}" class="snow-input">
                    @error('expire_date')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="snow-label" for="prefix">Prefijo (opcional, hasta 6 caracteres)</label>
                    <input id="prefix" name="prefix" type="text" maxlength="6" value="{{ old('prefix') }}" class="snow-input uppercase">
                    @error('prefix')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex flex-wrap items-center gap-2 md:col-span-2">
                    <button type="submit" class="snow-btn-primary">Emitir</button>
                    <a href="{{ route('admin.screens.index', $screen) }}" class="snow-btn-secondary">Cancelar</a>
                </div>
            </form>
        @endif
    </div>
</x-admin.layouts.app>
