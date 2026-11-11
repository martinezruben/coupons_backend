<x-admin.layouts.app :title="'Cierre de caja'">
    <div class="space-y-4 pb-6">
        <form method="GET" action="{{ route('admin.cierre-caja.index') }}" class="snow-card flex flex-wrap items-end gap-2 rounded-xl border border-slate-200/90 bg-white p-3 shadow-hope-card">
            <div class="flex min-w-[9rem] flex-col gap-0.5">
                <label class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500" for="cc-loc">Localidad</label>
                <select id="cc-loc" name="filter[location_id]" class="hope-filter-select">
                    @foreach(($gridFilterOptions['location_id'] ?? collect()) as $opt)
                        <option value="{{ $opt['id'] }}" @selected((string) $filterLocationId === (string) $opt['id'])>{{ $opt['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex min-w-[9rem] flex-col gap-0.5">
                <label class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500" for="cc-from">Desde</label>
                <input id="cc-from" type="date" name="filter[date_from]" value="{{ $filterDateFrom }}" class="hope-filter-select">
            </div>
            <div class="flex min-w-[9rem] flex-col gap-0.5">
                <label class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500" for="cc-to">Hasta</label>
                <input id="cc-to" type="date" name="filter[date_to]" value="{{ $filterDateTo }}" class="hope-filter-select">
            </div>
            <button type="submit" class="rounded-md bg-primary-600 px-3 py-1.5 text-[11px] font-semibold text-white shadow-sm transition hover:bg-primary-700">Aplicar</button>
            <a href="{{ route('admin.cierre-caja.index') }}" class="rounded-md py-1.5 text-[11px] font-medium text-slate-500 hover:text-primary-600">Limpiar</a>
            @if($canExport ?? false)
                <a href="{{ route('admin.cierre-caja.export', request()->query()) }}"
                   class="ml-auto inline-flex h-8 items-center gap-1 rounded-md border border-slate-200 bg-white px-3 text-[11px] font-semibold text-slate-600 shadow-sm transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700">
                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Excel
                </a>
            @endif
        </form>

        <div class="snow-card overflow-x-auto rounded-xl border border-slate-200/90 bg-white shadow-hope-card">
            <div class="border-b border-slate-100 px-4 py-3">
                <h3 class="text-sm font-semibold text-slate-900">Arqueo por turno</h3>
                <p class="text-[10px] text-slate-500">Turnos con transacciones PAID emparejadas por localidad + nº de turno + ventana de tiempo · Turnos abiertos se calculan «hasta ahora»</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left text-[11px]">
                    <thead class="snow-table-head">
                        <tr>
                            <th class="px-3 py-2 font-semibold">Localidad</th>
                            <th class="px-3 py-2 font-semibold">Dispositivo</th>
                            <th class="px-3 py-2 font-semibold">Usuario</th>
                            <th class="px-3 py-2 font-semibold">Turno</th>
                            <th class="px-3 py-2 font-semibold">Inicio / Fin</th>
                            <th class="px-3 py-2 font-semibold text-right">Efectivo</th>
                            <th class="px-3 py-2 font-semibold text-right">Tarjeta</th>
                            <th class="px-3 py-2 font-semibold text-right">Transf.</th>
                            <th class="px-3 py-2 font-semibold text-right">Otro</th>
                            <th class="px-3 py-2 font-semibold text-right">Total ventas</th>
                            <th class="px-3 py-2 font-semibold text-right">Efectivo esp.</th>
                            <th class="px-3 py-2 font-semibold text-right">Declarado</th>
                            <th class="px-3 py-2 font-semibold text-right">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse ($rows as $r)
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-3 py-2.5 font-medium text-slate-800">{{ $r['location'] }}</td>
                                <td class="px-3 py-2.5 text-slate-600">{{ $r['device'] }}</td>
                                <td class="px-3 py-2.5 text-slate-600">{{ $r['user'] }}</td>
                                <td class="px-3 py-2.5 tabular-nums text-slate-700">#{{ $r['shift_number'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-[10px] tabular-nums text-slate-500">
                                    {{ $r['start_time']?->format('d/m H:i') ?? '—' }} → {{ $r['end_time']?->format('d/m H:i') ?? 'abierto' }}
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">${{ number_format($r['methods']['CASH'], 2) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">${{ number_format($r['methods']['CARD'], 2) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">${{ number_format($r['methods']['TRANSFER'], 2) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">${{ number_format($r['methods']['OTHER'], 2) }}</td>
                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-slate-900">${{ number_format($r['total_sales'], 2) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">${{ number_format($r['expected_cash'], 2) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-slate-700">{{ $r['closing_balance'] === null ? '—' : '$'.number_format($r['closing_balance'], 2) }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    @if($r['difference'] === null)
                                        <span class="text-[10px] text-slate-500">sin declarar</span>
                                    @else
                                        @php
                                            $ok = abs((float) $r['difference']) < 0.01;
                                        @endphp
                                        <span class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-semibold {{ $ok ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' }}">
                                            ${{ number_format($r['difference'], 2) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="13" class="px-4 py-8 text-center text-[11px] text-slate-500">No hay turnos en el periodo seleccionado.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin.layouts.app>
