@php
    $modeLabel = ['assign' => 'asignados a', 'now' => 'activados ahora para', 'scheduled' => 'programados para activarse el '.($at?->format('d/m/Y H:i')).' para'][$mode];
@endphp
<x-admin.layouts.app :title="'Confirmar — '.$batch->code">
    <div class="snow-card">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-medium text-slate-800">Confirma la operación</h2>
        </div>
        <div class="space-y-4 p-5">
            <p class="text-sm">
                Se van a dejar <strong class="tabular-nums">{{ number_format($summary['count']) }}</strong> cupones del lote <strong>{{ $batch->code }}</strong>
                {{ $modeLabel }} <strong>{{ $customer->name }}</strong>
                @if($summary['first_seq'] && $summary['last_seq'])(secuencias {{ number_format($summary['first_seq']) }} a {{ number_format($summary['last_seq']) }})@endif.
            </p>
            @if($mode === 'now')
                <div class="alert alert-warning">Los cupones quedarán vigentes y cobrables de inmediato.</div>
            @endif
            <form method="POST" action="{{ route('admin.coupon-batches.activate', $batch) }}" class="flex flex-wrap gap-2">
                @csrf
                @foreach($payload as $name => $value)
                    @if(is_scalar($value) || $value === null)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="hidden" name="step" value="confirm">
                <button type="submit" class="snow-btn-primary">Confirmar</button>
                <a href="{{ route('admin.coupon-batches.activate.form', $batch) }}" class="snow-btn-secondary">Volver</a>
            </form>
        </div>
    </div>
</x-admin.layouts.app>
