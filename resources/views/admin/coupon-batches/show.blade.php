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
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <h2 class="text-base font-medium text-slate-800">{{ $batch->name }}</h2>
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
                @if($can['activate'] && $batch->status === 'ready')
                    <a href="{{ route('admin.coupon-batches.activate.form', $batch) }}" class="snow-btn-secondary">Asignar y activar</a>
                @endif
                <a href="{{ route('admin.screens.index', $batch->coupon_type === 'QRCODE' ? 'coupons' : 'coupon-barcodes') }}?filter[batch_id]={{ $batch->id }}" class="snow-btn-secondary">Ver cupones del lote</a>
            </div>
        </div>

        @if($batch->hasExportFile())
            <div class="border-b border-slate-200 bg-slate-100 px-5 py-3 text-sm text-slate-600">
                El archivo con los tokens vence el {{ $batch->export_expires_at->format('d/m/Y H:i') }} o al descargarse. Después no hay forma de recuperarlos.
            </div>
        @elseif($batch->status === 'ready')
            <div class="border-b border-slate-200 bg-slate-100 px-5 py-3 text-sm text-slate-600">
                @if($batch->export_downloaded_at)
                    El archivo se descargó el {{ $batch->export_downloaded_at->format('d/m/Y H:i') }} y ya no existe en el servidor.
                @else
                    El archivo venció sin descargarse y se eliminó.
                @endif
            </div>
        @endif

        <div class="grid grid-cols-2 gap-4 p-5 sm:grid-cols-5">
            @foreach($labels as $status => $label)
                <div>
                    <div class="subheader">{{ $label }}</div>
                    <div class="stat-value">{{ number_format($funnel[$status] ?? 0) }}</div>
                </div>
            @endforeach
        </div>
        <div class="px-5 pb-5">
            <div class="flex h-2 overflow-hidden rounded-full bg-slate-100" role="img" aria-label="Estado de los cupones del lote">
                @foreach(['GENERATED' => 'bg-slate-400', 'ASSIGNED' => 'bg-sky-500', 'ACTIVE' => 'bg-emerald-500', 'INACTIVE' => 'bg-amber-500', 'VOID' => 'bg-rose-500'] as $status => $color)
                    <div class="{{ $color }}" style="width: {{ round(($funnel[$status] ?? 0) / $total * 100, 2) }}%"></div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="snow-card mt-6">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-medium text-slate-800">Activaciones del lote</h2>
        </div>
        <div class="overflow-x-auto">
            <table>
                <thead><tr>
                    <th class="text-left">Solicitada</th><th class="text-left">Cliente</th><th class="text-left">Modo</th>
                    <th class="text-left">Activar el</th><th class="text-right">Cupones</th><th class="text-left">Estado</th><th></th>
                </tr></thead>
                <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td>
                                {{ $order->created_at->format('d/m/Y H:i') }}
                                <span class="block text-xs text-slate-500">por {{ $order->requester?->name ?? '—' }}</span>
                            </td>
                            <td>
                                {{ $order->customer?->name ?? '—' }}
                                @if($order->reason)<span class="block text-xs text-slate-500">{{ $order->reason }}</span>@endif
                            </td>
                            <td><x-admin.snow.badge field="mode" :value="$order->mode" /></td>
                            <td>{{ $order->activate_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ number_format($order->coupons_count) }}</td>
                            <td><x-admin.snow.badge field="status" :value="$order->status" /></td>
                            <td class="text-right">
                                @if($can['cancel'] && $order->status === 'pending')
                                    <form method="POST" action="{{ route('admin.activation-orders.cancel', $order) }}" class="inline"
                                          onsubmit="const r = prompt('Motivo de la cancelación (opcional):'); if (r === null) return false; this.reason.value = r;">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="snow-btn-secondary snow-btn-sm">Cancelar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-8 text-center text-slate-500">Aún no se asignó ni activó ningún cupón de este lote.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($can['void'] && in_array($batch->status, ['ready', 'generating'], true))
        <div class="snow-card mt-6">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div>
                    <h2 class="text-base font-medium text-slate-800">Anular lote</h2>
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
