@props([
    'value' => null,
    'field' => '',
])

@php
    $raw = $value;
    if ($value === null || $value === '') {
        $showEmpty = true;
    } else {
        $showEmpty = false;
    }

    if (! $showEmpty) {
        $boolVal = null;
        if (in_array($field, ['is_active', 'is_synced', 'is_enabled', 'is_favorite'], true)) {
            $boolVal = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($boolVal === null) {
                $boolVal = (bool) (int) $raw;
            }
        }

        if (in_array($field, ['is_active', 'is_synced', 'is_enabled', 'is_favorite'], true)) {
            $active = $boolVal;
            $variant = $active
                ? ['bg' => 'bg-emerald-50', 'ink' => 'text-emerald-800', 'dot' => 'bg-emerald-500', 'text' => 'Sí']
                : ['bg' => 'bg-snow-100', 'ink' => 'text-snow-600', 'dot' => 'bg-snow-400', 'text' => 'No'];
        } elseif ($field === 'status' && is_string($raw)) {
            $s = strtoupper($raw);
            $variant = match (true) {
                in_array($s, ['SUCCESS', 'COMPLETED', 'PAID', 'COMPLETE', 'ACTIVE', 'APPROVED'], true) => ['bg' => 'bg-emerald-50', 'ink' => 'text-emerald-800', 'dot' => 'bg-emerald-500', 'text' => $raw],
                in_array($s, ['FAILED', 'VOIDED', 'REJECTED', 'REVOKED', 'EXPIRED'], true) => ['bg' => 'bg-rose-50', 'ink' => 'text-rose-800', 'dot' => 'bg-rose-500', 'text' => $raw],
                in_array($s, ['PENDING', 'IN_PROGRESS', 'IN PROGRESS'], true) => ['bg' => 'bg-violet-50', 'ink' => 'text-violet-800', 'dot' => 'bg-violet-500', 'text' => $raw],
                default => ['bg' => 'bg-amber-50', 'ink' => 'text-amber-900', 'dot' => 'bg-amber-500', 'text' => $raw],
            };
        } elseif ($field === 'response_status' && (is_numeric($raw) || is_string($raw))) {
            $code = (int) $raw;
            $variant = match (true) {
                $code >= 200 && $code < 300 => ['bg' => 'bg-emerald-50', 'ink' => 'text-emerald-800', 'dot' => 'bg-emerald-500', 'text' => (string) $code],
                $code >= 400 && $code < 500 => ['bg' => 'bg-amber-50', 'ink' => 'text-amber-900', 'dot' => 'bg-amber-500', 'text' => (string) $code],
                $code >= 500 => ['bg' => 'bg-rose-50', 'ink' => 'text-rose-800', 'dot' => 'bg-rose-500', 'text' => (string) $code],
                default => ['bg' => 'bg-snow-50', 'ink' => 'text-snow-700', 'dot' => 'bg-snow-400', 'text' => (string) $raw],
            };
        } elseif ($field === 'operation' && is_string($raw)) {
            $variant = strtoupper($raw) === 'PUSH'
                ? ['bg' => 'bg-amber-50', 'ink' => 'text-amber-900', 'dot' => 'bg-amber-500', 'text' => $raw]
                : ['bg' => 'bg-sky-50', 'ink' => 'text-sky-900', 'dot' => 'bg-sky-500', 'text' => $raw];
        } elseif ($field === 'payment_method' && is_string($raw)) {
            $s = strtoupper($raw);
            $variant = match (true) {
                $s === 'CASH' || str_contains($s, 'CASH') => ['bg' => 'bg-emerald-50', 'ink' => 'text-emerald-800', 'dot' => 'bg-emerald-500', 'text' => $raw],
                $s === 'CARD' || str_contains($s, 'CARD') => ['bg' => 'bg-sky-50', 'ink' => 'text-sky-900', 'dot' => 'bg-sky-500', 'text' => $raw],
                $s === 'TRANSFER' => ['bg' => 'bg-violet-50', 'ink' => 'text-violet-800', 'dot' => 'bg-violet-500', 'text' => $raw],
                default => ['bg' => 'bg-amber-50', 'ink' => 'text-amber-900', 'dot' => 'bg-amber-500', 'text' => $raw],
            };
        } elseif ($field === 'role' && is_string($raw)) {
            $variant = ['bg' => 'bg-primary-50', 'ink' => 'text-primary-700', 'dot' => 'bg-primary-500', 'text' => $raw];
        } else {
            $variant = ['bg' => 'bg-snow-50', 'ink' => 'text-snow-700', 'dot' => 'bg-snow-400', 'text' => is_scalar($raw) || $raw === null ? (string) $raw : '—'];
        }

        // Valores enumerados guardados en inglés se muestran en español (config/admin_labels.php)
        if (in_array($field, ['status', 'payment_method', 'role', 'operation'], true)) {
            $variant['text'] = \App\Support\AdminGridCell::valueLabel($field, $raw);
        }

        $variant['text'] = \Illuminate\Support\Str::lower($variant['text']);
    }
@endphp

@if($showEmpty)
    <span class="text-snow-400">—</span>
@else
    <span class="snow-tag {{ $variant['bg'] }} {{ $variant['ink'] }}" title="{{ $variant['text'] }}">
        <span class="h-[5px] w-[5px] shrink-0 rounded-full {{ $variant['dot'] }}"></span>
        <span class="truncate">{{ $variant['text'] }}</span>
    </span>
@endif
