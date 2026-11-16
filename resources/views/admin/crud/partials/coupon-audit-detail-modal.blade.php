{{-- Modal de detalle de la auditoría de cupones; requiere x-data="couponAuditDetail(...)" en un ancestro --}}
<div x-show="open" x-cloak class="fixed inset-0 z-[1100] flex items-center justify-center bg-slate-900/45 p-3 backdrop-blur-[2px] sm:p-4"
     @click.self="closeDetail()" @keydown.escape.window="closeDetail()" role="dialog" aria-modal="true" aria-labelledby="coupon-audit-title">
    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-[14px] bg-white shadow-2xl ring-1 ring-black/5" @click.stop>
        <div class="flex shrink-0 items-start justify-between gap-2 border-b border-slate-100 px-5 py-3">
            <div class="min-w-0">
                <h2 id="coupon-audit-title" class="text-base font-semibold text-slate-900" x-text="row ? row.action_label : 'Detalle de auditoría'"></h2>
                <p class="mt-0.5 text-sm text-slate-500" x-text="row?.description ?? ''"></p>
            </div>
            <button type="button" class="shrink-0 rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" @click="closeDetail()" aria-label="Cerrar">
                <x-admin.snow.icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm">
            <div x-show="loading" class="py-10 text-center text-slate-500">Cargando…</div>
            <div x-show="error && !loading" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-800" x-text="error"></div>

            <template x-if="row && !loading">
                <div class="space-y-4">
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-2">
                        <div><dt class="text-xs uppercase tracking-wide text-slate-500">Fecha y hora</dt><dd class="tabular-nums text-slate-900" x-text="row.created_at"></dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-slate-500">Quién</dt>
                            <dd class="text-slate-900"><span x-text="row.actor ? row.actor.name : 'Sistema (proceso automático)'"></span>
                                <span class="block text-xs text-slate-500" x-show="row.actor" x-text="row.actor ? row.actor.kind + (row.actor.email ? ' · ' + row.actor.email : '') : ''"></span></dd></div>
                        <div x-show="row.coupon_number"><dt class="text-xs uppercase tracking-wide text-slate-500">Cupón</dt>
                            <dd class="text-slate-900"><span x-text="row.coupon_type"></span> · <span class="font-mono text-xs" x-text="row.coupon_number"></span>
                                <a x-show="row.coupon_url" :href="row.coupon_url" class="ml-1 text-primary-600 hover:underline">Ver historial</a></dd></div>
                        <div><dt class="text-xs uppercase tracking-wide text-slate-500">IP</dt><dd class="tabular-nums text-slate-900" x-text="row.ip ?? '—'"></dd></div>
                        <div x-show="row.old_value !== null || row.new_value !== null"><dt class="text-xs uppercase tracking-wide text-slate-500">Antes → Después</dt>
                            <dd class="text-slate-900"><span class="text-red-700" x-text="row.old_value ?? '—'"></span> → <span class="text-green-700" x-text="row.new_value ?? '—'"></span></dd></div>
                    </dl>

                    <div>
                        <h3 class="mb-1 text-xs font-bold uppercase tracking-[0.04em] text-slate-500">Comentario completo</h3>
                        <p class="whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-3 text-slate-800" x-text="row.reason ?? 'Sin comentario.'"></p>
                    </div>

                    <div x-show="row.batch">
                        <h3 class="mb-1 text-xs font-bold uppercase tracking-[0.04em] text-slate-500">Lote</h3>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border border-slate-200 p-3 text-xs">
                            <dt class="text-slate-500">Código</dt><dd><a x-show="row.batch?.url" :href="row.batch?.url" class="text-primary-600 hover:underline" x-text="row.batch?.code"></a><span x-show="!row.batch?.url" x-text="row.batch?.code"></span></dd>
                            <dt class="text-slate-500">Nombre</dt><dd x-text="row.batch?.name"></dd>
                            <dt class="text-slate-500">Estado</dt><dd x-text="row.batch?.status"></dd>
                            <dt class="text-slate-500">Cantidad · monto</dt><dd><span x-text="row.batch?.quantity"></span> cupones · $<span x-text="row.batch?.amount"></span></dd>
                            <dt class="text-slate-500">Generado por</dt><dd x-text="row.batch?.created_by ?? '—'"></dd>
                        </dl>
                    </div>

                    <div x-show="row.order">
                        <h3 class="mb-1 text-xs font-bold uppercase tracking-[0.04em] text-slate-500">Activación</h3>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 rounded-lg border border-slate-200 p-3 text-xs">
                            <dt class="text-slate-500">Modo</dt><dd x-text="row.order?.mode"></dd>
                            <dt class="text-slate-500">Estado</dt><dd x-text="row.order?.status"></dd>
                            <dt class="text-slate-500">Cliente</dt><dd x-text="row.order?.customer ?? '—'"></dd>
                            <dt class="text-slate-500">Cupones</dt><dd x-text="row.order?.coupons"></dd>
                            <dt class="text-slate-500">Selección</dt><dd><span x-text="row.order?.selection"></span> <span class="font-mono" x-show="row.order?.selection_detail" x-text="pretty(row.order?.selection_detail)"></span></dd>
                            <dt class="text-slate-500" x-show="row.order?.activate_at">Programada para</dt><dd x-show="row.order?.activate_at" x-text="row.order?.activate_at"></dd>
                            <dt class="text-slate-500">Aplicada</dt><dd x-text="row.order?.applied_at ?? '—'"></dd>
                            <dt class="text-slate-500" x-show="row.order?.cancelled_at">Cancelada</dt><dd x-show="row.order?.cancelled_at" x-text="(row.order?.cancelled_at ?? '') + ' por ' + (row.order?.cancelled_by ?? '—')"></dd>
                            <dt class="text-slate-500">Solicitada por</dt><dd x-text="row.order?.requested_by ?? '—'"></dd>
                            <dt class="text-slate-500" x-show="row.order?.reason">Motivo</dt><dd x-show="row.order?.reason" x-text="row.order?.reason"></dd>
                        </dl>
                    </div>

                    <div x-show="row.panel">
                        <h3 class="mb-1 text-xs font-bold uppercase tracking-[0.04em] text-slate-500">Acción en el panel</h3>
                        <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1 rounded-lg border border-slate-200 p-3 text-xs">
                            <dt class="text-slate-500">Qué hizo</dt><dd x-text="row.panel?.summary"></dd>
                            <dt class="text-slate-500">Ruta</dt><dd class="break-all" x-text="(row.panel?.method ?? '') + ' ' + (row.panel?.route ?? '')"></dd>
                            <dt class="text-slate-500">Dirección</dt><dd class="break-all" x-text="row.panel?.url ?? '—'"></dd>
                            <dt class="text-slate-500">Navegador</dt><dd class="break-all" x-text="row.panel?.user_agent ?? '—'"></dd>
                            <dt class="text-slate-500">Datos enviados</dt><dd><pre class="max-h-32 overflow-auto rounded border border-slate-200 bg-white p-2 text-[11px]" x-text="pretty(row.panel?.input)"></pre></dd>
                        </dl>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex shrink-0 justify-end border-t border-slate-100 bg-slate-50 px-5 py-2.5">
            <button type="button" class="snow-btn-secondary" @click="closeDetail()">Cerrar</button>
        </div>
    </div>
</div>
