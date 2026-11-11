{{-- Modal detalle de log de auditoría; requiere x-data="auditLogDetail(...)" en un ancestro --}}
<div
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-3 backdrop-blur-[2px] sm:p-4"
    @click.self="closeDetail()"
    @keydown.escape.window="closeDetail()"
    role="dialog"
    aria-modal="true"
    aria-labelledby="audit-log-detail-title"
>
    <div
        class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-[14px] bg-white shadow-2xl ring-1 ring-black/5"
        @click.stop
    >
        <div class="flex shrink-0 items-start justify-between gap-2 border-b border-slate-100 px-4 py-3 sm:px-5">
            <div class="min-w-0">
                <h2 id="audit-log-detail-title" class="text-sm font-semibold text-slate-900">Detalle de auditoría</h2>
                <p class="mt-0.5 text-[10px] text-slate-500" x-text="row ? `${row.entity_type ?? '—'} · ${row.entity_id ?? '—'}` : ''"></p>
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
            <div x-show="loading" class="flex items-center justify-center py-12 text-sm text-slate-500">Cargando…</div>
            <div x-show="error && !loading" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800" x-text="error"></div>

            <div x-show="!loading && !error && row" class="space-y-4">
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-[10px] sm:grid-cols-2">
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Fecha / hora</dt>
                        <dd class="tabular-nums text-slate-800" x-text="row?.created_at ?? '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Acción</dt>
                        <dd class="text-slate-800" x-text="row ? actionLabel(row.action) : '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">Usuario admin</dt>
                        <dd class="text-slate-800" x-text="row?.admin_name ?? '—'"></dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="font-semibold uppercase tracking-wide text-slate-500">IP</dt>
                        <dd class="tabular-nums text-slate-800" x-text="row?.ip ?? '—'"></dd>
                    </div>
                </dl>

                <div>
                    <h3 class="mb-1 text-[10px] font-mono font-bold uppercase tracking-widest text-slate-500">Cambios</h3>
                    <template x-if="row && changeEntries(row.changes).length">
                        <table class="min-w-full divide-y divide-slate-100 text-left text-[10px]">
                            <thead class="snow-table-head">
                                <tr>
                                    <th class="px-2 py-1 font-semibold">Campo</th>
                                    <th class="px-2 py-1 font-semibold">Antes</th>
                                    <th class="px-2 py-1 font-semibold">Después</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <template x-for="entry in changeEntries(row.changes)" :key="entry.field">
                                    <tr>
                                        <td class="px-2 py-1 font-medium text-slate-700" x-text="entry.field"></td>
                                        <td class="max-w-[10rem] truncate px-2 py-1 text-slate-600" x-text="entry.before"></td>
                                        <td class="max-w-[10rem] truncate px-2 py-1 text-slate-600" x-text="entry.after"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </template>
                    <template x-if="row && !changeEntries(row.changes).length">
                        <p class="text-[10px] text-slate-500">Sin cambios de campos registrados (creación, eliminación o sesión).</p>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex shrink-0 justify-end border-t border-slate-100 bg-slate-50/80 px-4 py-2.5 sm:px-5">
            <button
                type="button"
                class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                @click="closeDetail()"
            >
                Cerrar
            </button>
        </div>
    </div>
</div>
