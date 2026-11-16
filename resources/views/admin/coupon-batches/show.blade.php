@php
    $labels = ['GENERATED' => 'Generados', 'ASSIGNED' => 'Asignados', 'ACTIVE' => 'Activos', 'INACTIVE' => 'Inactivos', 'VOID' => 'Anulados'];
    $total = max(1, (int) $funnel->sum());
    $generating = $batch->status === 'generating';
@endphp
<x-admin.layouts.app :title="'Lote '.$batch->code">
    @if($generating)
        <meta http-equiv="refresh" content="5">
        <div class="alert alert-warning" role="status">El lote se está generando. Esta página se actualiza sola.</div>
    @elseif($batch->status === 'failed')
        <div class="alert alert-danger" role="alert">La generación falló y no quedó ningún cupón. Crea un lote nuevo. {{ $batch->error }}</div>
    @elseif($batch->status === 'void')
        <div class="alert alert-warning" role="status">Lote anulado. Los cupones que ya estaban activos siguen vigentes.</div>
    @endif

    <div class="snow-card">
        <div class="flex flex-wrap items-start justify-between gap-5 px-7 py-6">
            <div>
                <h2 class="!text-3xl !font-bold">{{ $batch->name }}</h2>
                <p class="text-sm text-slate-500">
                    {{ $batch->code }} · {{ $batch->coupon_type === 'QRCODE' ? 'QR' : 'Código de barras' }} · {{ number_format($batch->quantity) }} cupones de {{ \App\Support\Format::money($batch->amount) }}
                    @if($batch->expire_date) · vence {{ $batch->expire_date->format('d/m/Y') }} @endif
                </p>
                <p class="text-xs text-slate-500">Generado por {{ $batch->creator?->name ?? '—' }} @if($batch->generated_at) el {{ $batch->generated_at->format('d/m/Y H:i') }} @endif</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($can['download'] && $batch->hasExportFile())
                    <a href="{{ route('admin.coupon-batches.download', $batch) }}" class="snow-btn-primary">Descargar archivo (una sola vez)</a>
                @endif
                @if($can['operate'] && $batch->status === 'ready')
                    <a href="{{ route('admin.coupon-batches.activate.form', $batch) }}" class="snow-btn-secondary">Asignar y activar</a>
                @endif
                <a href="{{ route('admin.coupons.index') }}?type={{ $batch->coupon_type }}&batch={{ $batch->id }}" class="snow-btn-secondary">Ver cupones del lote</a>
            </div>
        </div>

        @if($restricted)
            <div class="mx-7 mb-4 rounded-[14px] bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
                Tu alcance está limitado a los clientes que te asignaron: ves los cupones disponibles (generados) y solo los de tus clientes.
            </div>
        @endif

        @if($batch->hasExportFile())
            <div class="mx-7 mb-4 rounded-[14px] bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
                El archivo con los tokens vence el {{ $batch->export_expires_at->format('d/m/Y H:i') }} o al descargarse. Después no hay forma de recuperarlos.
            </div>
        @elseif($batch->status === 'ready')
            <div class="mx-7 mb-4 rounded-[14px] bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
                @if($batch->export_downloaded_at)
                    El archivo se descargó el {{ $batch->export_downloaded_at->format('d/m/Y H:i') }} y ya no existe en el servidor.
                @else
                    El archivo venció sin descargarse y se eliminó.
                @endif
            </div>
        @endif

        <div class="perf" style="margin-top: 1.25rem"></div>
        <div class="flex flex-col gap-5 px-7 pb-7 pt-6">
            <div class="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-4">
                @foreach($labels as $status => $label)
                    <div>
                        <span class="eyebrow">{{ $label }}</span>
                        <div class="font-display text-[40px] font-bold leading-[1.1] {{ $status === 'ACTIVE' ? 'text-green-600' : '' }}">{{ number_format($funnel[$status] ?? 0) }}</div>
                    </div>
                @endforeach
            </div>
            <div class="seg" role="img" aria-label="Estado de los cupones del lote">
                @foreach(['GENERATED' => '#C9D1C5', 'ASSIGNED' => '#74C58D', 'ACTIVE' => '#1E9E5A', 'INACTIVE' => '#D8941A', 'VOID' => '#E15B4E'] as $status => $color)
                    <div style="flex: {{ $funnel[$status] ?? 0 }}; background: {{ $color }}"></div>
                @endforeach
            </div>
        </div>
    </div>

    <section class="snow-card mt-6 flex flex-col gap-4 px-7 py-6">
        <h2>Activaciones del lote</h2>
        @forelse($orders as $order)
            <div class="grid grid-cols-[repeat(auto-fit,minmax(160px,1fr))] items-start gap-x-6 gap-y-4 rounded-2xl bg-slate-50 px-5 py-4">
                <div><span class="eyebrow">Solicitada</span><div class="mt-1 font-semibold">{{ $order->created_at->format('d/m/Y H:i') }}</div><div class="font-mono text-xs text-slate-500">por {{ $order->requester?->name ?? '—' }}</div></div>
                <div><span class="eyebrow">Cliente</span><div class="mt-1 font-semibold">{{ $order->customer?->name ?? '—' }}</div>@if($order->reason)<div class="text-[13px] text-slate-500">{{ $order->reason }}</div>@endif</div>
                <div><span class="eyebrow">Modo</span><div class="mt-1.5"><x-admin.snow.badge field="mode" :value="$order->mode" /></div></div>
                <div><span class="eyebrow">Activar el</span><div class="mt-1 text-slate-500">{{ $order->activate_at?->format('d/m/Y H:i') ?? '—' }}</div></div>
                <div><span class="eyebrow">Cupones</span><div class="font-display text-2xl font-bold">{{ number_format($order->coupons_count) }}</div></div>
                <div><span class="eyebrow">Estado</span><div class="mt-1.5"><x-admin.snow.badge field="status" :value="$order->status" /></div>
                    @if($can['cancel'] && $order->status === 'pending')
                        <form method="POST" action="{{ route('admin.activation-orders.cancel', $order) }}" class="mt-2"
                              onsubmit="const r = prompt('Motivo de la cancelación (opcional):'); if (r === null) return false; this.reason.value = r;">
                            @csrf
                            <input type="hidden" name="reason" value="">
                            <button type="submit" class="snow-btn-secondary snow-btn-sm">Cancelar</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <p class="py-6 text-center text-slate-500">Aún no se asignó ni activó ningún cupón de este lote.</p>
        @endforelse
    </section>

    @if($can['void'] && in_array($batch->status, ['ready', 'generating'], true))
        <div class="snow-card mt-6" style="border-color: #F0C4BE">
            <div class="flex flex-wrap items-center justify-between gap-3 px-7 py-5">
                <div>
                    <h2>Anular lote</h2>
                    <p class="text-sm text-slate-500">Anula los cupones que aún no están activos y cancela las activaciones programadas. Los activos no se tocan.</p>
                </div>
                <form method="POST" action="{{ route('admin.coupon-batches.void', $batch) }}"
                      onsubmit="const r = prompt('Motivo de la anulación (mínimo 5 caracteres):'); if (!r || r.trim().length < 5) return false; this.reason.value = r;">
                    @csrf
                    <input type="hidden" name="reason" value="">
                    <button type="submit" class="snow-btn-danger">Anular lote</button>
                </form>
            </div>
        </div>
    @endif
</x-admin.layouts.app>
