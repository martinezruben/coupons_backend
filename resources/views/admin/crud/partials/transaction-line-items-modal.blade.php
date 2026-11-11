{{-- Modal detalle líneas y pagos de transacción; requiere x-data="transactionLineItemsDetail(...)" en un ancestro --}}
<div
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/45 p-3 backdrop-blur-[2px] sm:p-4"
    @click.self="closeDetail()"
    @keydown.escape.window="closeDetail()"
    role="dialog"
    aria-modal="true"
    aria-labelledby="tx-line-items-title"
>
    <div
        class="flex max-h-[min(92vh,720px)] w-full max-w-3xl flex-col overflow-hidden rounded-[14px] bg-white shadow-2xl ring-1 ring-black/5"
        @click.stop
    >
        <div class="flex shrink-0 items-start justify-between gap-2 border-b border-slate-100 px-4 py-3 sm:px-5">
            <div class="min-w-0">
                <h2 id="tx-line-items-title" class="text-sm font-semibold text-slate-900">
                    Detalle de transacción
                </h2>
                <p class="mt-0.5 truncate text-[10px] text-slate-500" x-show="detail?.external_id" x-text="detail ? detail.external_id : ''"></p>
            </div>
            <button
                type="button"
                class="shrink-0 rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                @click="closeDetail()"
                aria-label="Cerrar"
            >
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3 sm:px-5">
            <div x-show="loading" class="flex items-center justify-center py-12 text-sm text-slate-500">
                Cargando…
            </div>
            <div x-show="error && !loading" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" x-text="error"></div>

            <template x-if="!loading && !error && detail">
            <div class="space-y-4">
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-[10px] sm:grid-cols-2">
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Fecha / hora</dt>
                        <dd class="tabular-nums text-slate-800" x-text="detail.occurred_at ?? '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Estado</dt>
                        <dd class="text-slate-800" x-text="detail.status ?? '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Método de pago</dt>
                        <dd class="text-slate-800" x-text="(detail.payment_methods && detail.payment_methods.length) ? detail.payment_methods.join(', ') : '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Total</dt>
                        <dd class="tabular-nums text-slate-800" x-text="detail.total ?? '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:col-span-2">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Localidad</dt>
                        <dd class="text-slate-800" x-text="detail.location_name ?? '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5 sm:col-span-2">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Dispositivo</dt>
                        <dd class="text-slate-800" x-text="detail.device_label ?? '—'"></dd>
                    </div>
                </dl>

                <div>
                    <h3 class="mb-1.5 text-[10px] font-mono font-bold uppercase tracking-widest text-slate-500">Líneas</h3>
                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="w-full min-w-[640px] border-collapse text-left text-[10px]">
                            <thead class="border-b border-slate-100 bg-slate-50/90">
                                <tr>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">Producto</th>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">SKU</th>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">Cant.</th>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">P. unit.</th>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">Impuesto</th>
                                    <th class="whitespace-nowrap px-2 py-1 text-right font-semibold text-slate-600">Total línea</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="(row, idx) in (detail.items || [])" :key="idx">
                                    <tr>
                                        <td class="max-w-[12rem] truncate px-2 py-1 text-slate-800" x-text="row.product_name ?? '—'"></td>
                                        <td class="px-2 py-1 font-mono text-slate-700" x-text="row.product_sku ?? '—'"></td>
                                        <td class="px-2 py-1 tabular-nums text-slate-800" x-text="row.qty ?? '—'"></td>
                                        <td class="px-2 py-1 tabular-nums text-slate-800" x-text="row.unit_price ?? '—'"></td>
                                        <td class="px-2 py-1 tabular-nums text-slate-800" x-text="row.tax ?? '—'"></td>
                                        <td class="px-2 py-1 text-right font-medium tabular-nums text-slate-900" x-text="row.line_total ?? '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p x-show="!(detail.items && detail.items.length)" class="mt-1 text-[10px] text-slate-500">Sin líneas.</p>
                </div>

                <div>
                    <h3 class="mb-1.5 text-[10px] font-mono font-bold uppercase tracking-widest text-slate-500">Pagos</h3>
                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="w-full min-w-[360px] border-collapse text-left text-[10px]">
                            <thead class="border-b border-slate-100 bg-slate-50/90">
                                <tr>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">Método</th>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">Importe</th>
                                    <th class="whitespace-nowrap px-2 py-1 font-semibold text-slate-600">Referencia</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="(row, idx) in (detail.payments || [])" :key="idx">
                                    <tr>
                                        <td class="px-2 py-1 text-slate-800" x-text="row.payment_method ?? '—'"></td>
                                        <td class="px-2 py-1 tabular-nums text-slate-800" x-text="row.amount ?? '—'"></td>
                                        <td class="px-2 py-1 text-slate-700" x-text="row.reference ?? '—'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p x-show="!(detail.payments && detail.payments.length)" class="mt-1 text-[10px] text-slate-500">Sin pagos.</p>
                </div>
            </div>
            </template>
        </div>

        <div class="shrink-0 border-t border-slate-100 bg-slate-50/80 px-4 py-2.5 sm:px-5">
            <button
                type="button"
                class="w-full rounded-lg bg-primary-600 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-700 sm:w-auto sm:px-6"
                @click="closeDetail()"
            >
                Cerrar
            </button>
        </div>
    </div>
</div>
