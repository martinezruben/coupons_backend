@php
    /** @var \Illuminate\Database\Eloquent\Model $item */
    $admin = auth('admin')->user();
    $couponType = $screen === 'coupons' ? 'qr' : ($screen === 'coupon-barcodes' ? 'barcode' : null);
    $perm = fn (string $action) => ($p = \App\Support\AdminRbac::actionPermissions($screen)[$action]['permission'] ?? null) && $admin?->can($p);
    $btn = 'rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 shadow-sm hover:border-primary-300 hover:bg-primary-50';
@endphp

@if($couponType)
    @if($perm('toggle'))
        @php $isActive = (int) $item->coupon_active === 1; @endphp
        <form method="POST" action="{{ route('admin.coupons.toggle', [$couponType, $item->getKey()]) }}" class="inline"
              onsubmit="const r = prompt('Motivo para {{ $isActive ? 'desactivar' : 'activar' }} este cupón:'); if (!r || r.trim().length < 3) return false; this.reason.value = r;">
            @csrf
            <input type="hidden" name="active" value="{{ $isActive ? 0 : 1 }}">
            <input type="hidden" name="reason" value="">
            <button type="submit" class="{{ $btn }}">{{ $isActive ? 'Desactivar' : 'Activar' }}</button>
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
@elseif($screen === 'coupon-transactions' && $perm('reverse') && $item->reversed_of === null && (float) $item->transaction_amount > 0)
    <form method="POST" action="{{ route('admin.coupon-transactions.reverse', $item->getKey()) }}" class="inline"
          onsubmit="const r = prompt('Motivo de la reversa:'); if (!r || r.trim().length < 5) return false; this.reason.value = r;">
        @csrf
        <input type="hidden" name="reason" value="">
        <button type="submit" class="{{ $btn }}">Revertir</button>
    </form>
@endif
