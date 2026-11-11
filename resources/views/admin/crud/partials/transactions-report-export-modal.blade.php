{{-- Modal exportar reporte de transacciones; requiere x-data="transactionsReportModal(...)" en el mismo elemento que el botón --}}
<div
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-3 backdrop-blur-[2px] sm:p-4"
    @click.self="open = false"
    @keydown.escape.window="open = false"
    role="dialog"
    aria-modal="true"
    aria-labelledby="tx-report-export-title"
>
    <div
        class="flex w-full max-w-md flex-col overflow-hidden rounded-[14px] bg-white shadow-2xl ring-1 ring-black/5"
        @click.stop
    >
        <div class="flex shrink-0 items-start justify-between gap-2 border-b border-slate-100 px-4 py-3 sm:px-5">
            <div class="min-w-0">
                <h2 id="tx-report-export-title" class="text-sm font-semibold text-slate-900">
                    Descargar reporte de transacciones
                </h2>
                <p class="mt-0.5 text-[10px] text-slate-500">
                    Formato plano: una fila por transacción. Con detalle, una fila por línea de venta (SKU, producto y montos).
                </p>
            </div>
            <button
                type="button"
                class="shrink-0 rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                @click="open = false"
                :disabled="loading"
                aria-label="Cerrar"
            >
                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="space-y-3 px-4 py-3 sm:px-5">
            <div x-show="error" x-cloak class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" x-text="error"></div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-0.5">
                    <label for="tx-report-from" class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500">Desde</label>
                    <input
                        id="tx-report-from"
                        type="date"
                        x-model="dateFrom"
                        class="hope-filter-select w-full py-1.5 text-xs"
                        :disabled="loading"
                    >
                </div>
                <div class="flex flex-col gap-0.5">
                    <label for="tx-report-to" class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500">Hasta</label>
                    <input
                        id="tx-report-to"
                        type="date"
                        x-model="dateTo"
                        class="hope-filter-select w-full py-1.5 text-xs"
                        :disabled="loading"
                    >
                </div>
            </div>

            <div class="flex flex-col gap-0.5">
                <label for="tx-report-loc" class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500">Localidad</label>
                <select
                    id="tx-report-loc"
                    x-model="locationId"
                    class="hope-filter-select w-full py-1.5 text-xs"
                    :disabled="loading"
                >
                    <option value="">Todas las localidades</option>
                    <template x-for="loc in locations" :key="loc.id">
                        <option :value="loc.id" x-text="loc.name"></option>
                    </template>
                </select>
            </div>

            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-2">
                <input
                    id="tx-report-detail"
                    type="checkbox"
                    x-model="includeDetail"
                    class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                    :disabled="loading"
                >
                <span class="text-xs font-medium text-slate-700">Incluir detalle de líneas <span class="font-normal text-slate-500">(SKU, producto, cantidades y montos por línea)</span></span>
            </label>
        </div>

        <div class="flex shrink-0 flex-col gap-2 border-t border-slate-100 bg-slate-50/80 px-4 py-2.5 sm:flex-row sm:justify-end sm:px-5">
            <button
                type="button"
                class="w-full rounded-lg border border-slate-200 bg-white py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 sm:w-auto sm:px-4"
                @click="open = false"
                :disabled="loading"
            >
                Cancelar
            </button>
            <button
                type="button"
                class="w-full rounded-lg bg-primary-600 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-700 disabled:opacity-60 sm:w-auto sm:px-6"
                @click="submit()"
                :disabled="loading"
            >
                <span x-show="!loading">Descargar</span>
                <span x-show="loading" x-cloak>Generando…</span>
            </button>
        </div>
    </div>
</div>
