@php
    /** @var \Illuminate\Database\Eloquent\Model $item */
    $admin = auth('admin')->user();
    $couponType = $screen === 'coupons' ? 'qr' : ($screen === 'coupon-barcodes' ? 'barcode' : null);
    $perm = fn (string $action) => ($p = \App\Support\AdminRbac::actionPermissions($screen)[$action]['permission'] ?? null) && $admin?->can($p);
    $btn = 'snow-btn-secondary snow-btn-sm';
@endphp

@if($screen === 'coupon-batches')
    <a href="{{ route('admin.coupon-batches.show', $item) }}" class="{{ $btn }}">Ver</a>
    @if($perm('download') && $item->hasExportFile())
        <a href="{{ route('admin.coupon-batches.download', $item) }}" class="{{ $btn }}">Descargar</a>
    @endif
@elseif($screen === 'coupon-activations')
    @if($item->status === 'pending' && ($cancelPerm = \App\Support\AdminRbac::actionPermissions('coupon-batches')['cancel']['permission'] ?? null) && $admin?->can($cancelPerm))
        <form method="POST" action="{{ route('admin.activation-orders.cancel', $item) }}" class="inline"
              onsubmit="const r = prompt('Motivo de la cancelación (opcional):'); if (r === null) return false; this.reason.value = r;">
            @csrf
            <input type="hidden" name="reason" value="">
            <button type="submit" class="{{ $btn }}">Cancelar</button>
        </form>
    @endif
@elseif($couponType)
    @if($perm('toggle') && in_array($item->status, ['ACTIVE', 'INACTIVE'], true))
        @php $isActive = $item->status === 'ACTIVE'; @endphp
        <form method="POST" action="{{ route('admin.coupons.toggle', [$couponType, $item->getKey()]) }}" class="inline"
              onsubmit="const r = prompt('Motivo para {{ $isActive ? 'desactivar' : 'activar' }} este cupón:'); if (!r || r.trim().length < 3) return false; this.reason.value = r;">
            @csrf
            <input type="hidden" name="active" value="{{ $isActive ? 0 : 1 }}">
            <input type="hidden" name="reason" value="">
            <button type="submit" class="{{ $btn }}">{{ $isActive ? 'Desactivar' : 'Activar' }}</button>
        </form>
    @endif
    @if($perm('topup') && $item->status === 'ACTIVE')
        <form method="POST" action="{{ route('admin.coupons.topup', [$couponType, $item->getKey()]) }}" class="inline"
              onsubmit="const a = prompt('Monto a recargar:'); if (!a || isNaN(a) || Number(a) <= 0) return false; const n = prompt('Nota (opcional, ej. referencia del pago):') || ''; this.amount.value = a; this.note.value = n;">
            @csrf
            <input type="hidden" name="amount" value="">
            <input type="hidden" name="note" value="">
            <button type="submit" class="{{ $btn }}">Recargar</button>
        </form>
    @endif
    @if($perm('adjust'))
        <form method="POST" action="{{ route('admin.coupons.adjust', [$couponType, $item->getKey()]) }}" class="inline"
              onsubmit="const b = prompt('Nuevo saldo (máx. {{ number_format((float) $item->coupon_amount, 2, '.', '') }}):', '{{ number_format((float) $item->coupon_balance, 2, '.', '') }}'); if (b === null || b === '') return false; const r = prompt('Motivo del ajuste:'); if (!r || r.trim().length < 5) return false; this.balance.value = b; this.reason.value = r;">
            @csrf
            <input type="hidden" name="balance" value="">
            <input type="hidden" name="reason" value="">
            <button type="submit" class="{{ $btn }}">Ajustar saldo</button>
        </form>
    @endif
@elseif($screen === 'coupon-transactions' && $perm('reverse') && $item->kind === 'REDEEM' && $item->reversed_of === null && (float) $item->transaction_amount > 0)
    <form method="POST" action="{{ route('admin.coupon-transactions.reverse', $item->getKey()) }}" class="inline"
          onsubmit="const r = prompt('Motivo de la reversa:'); if (!r || r.trim().length < 5) return false; this.reason.value = r;">
        @csrf
        <input type="hidden" name="reason" value="">
        <button type="submit" class="{{ $btn }}">Revertir</button>
    </form>
@endif
