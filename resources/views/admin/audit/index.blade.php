<x-admin.layouts.app title="Auditoría">
    @php
        $tag = [
            'view' => 'bg-slate-100 text-slate-700', 'submit' => 'bg-blue-50 text-blue-800', 'created' => 'bg-green-50 text-green-800',
            'updated' => 'bg-amber-50 text-amber-800', 'deleted' => 'bg-red-50 text-red-800', 'login' => 'bg-indigo-50 text-indigo-800',
            'logout' => 'bg-indigo-50 text-indigo-800', 'blocked' => 'bg-red-50 text-red-800', 'unblocked' => 'bg-green-50 text-green-800',
        ];
        $val = fn ($v) => $v === null || $v === '' ? '—' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
    @endphp
    <div class="snow-card">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">
                Cada página que se abre, cada acción que se envía y cada dato que cambia queda registrado con fecha, usuario y movimiento.
                Las ediciones guardan el valor <strong>antes y después</strong>. Abre una fila para ver el detalle técnico.
            </p>
        </div>

        <form method="GET" action="{{ route('admin.audit.index') }}" class="grid grid-cols-2 items-end gap-2 border-b border-slate-200 p-3 sm:grid-cols-4 lg:grid-cols-8">
            <div class="col-span-2">
                <label class="snow-label" for="q">Buscar</label>
                <input id="q" name="q" type="search" value="{{ $f['q'] ?? '' }}" class="snow-input" placeholder="Detalle, usuario, id o ruta">
            </div>
            <div>
                <label class="snow-label" for="user">Usuario</label>
                <select id="user" name="user" class="snow-input"><option value="">Todos</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected(($f['user'] ?? '') === $u->id)>{{ $u->name }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="kind">Tipo de registro</label>
                <select id="kind" name="kind" class="snow-input">
                    @foreach(['all' => 'Todo', 'changes' => 'Cambios de datos', 'clicks' => 'Clics y consultas'] as $k => $l)<option value="{{ $k }}" @selected($f['kind'] === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="snow-label" for="action">Movimiento</label>
                <select id="action" name="action" class="snow-input"><option value="">Todos</option>@foreach($actions as $k => $l)<option value="{{ $k }}" @selected(($f['action'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div>
                <label class="snow-label" for="entity_type">Módulo</label>
                <select id="entity_type" name="entity_type" class="snow-input"><option value="">Todos</option>@foreach($entities as $k => $l)<option value="{{ $k }}" @selected(($f['entity_type'] ?? '') === $k)>{{ $l }}</option>@endforeach</select>
            </div>
            <div><label class="snow-label" for="from">Desde</label><input id="from" name="from" type="date" value="{{ $f['from'] ?? '' }}" class="snow-input"></div>
            <div><label class="snow-label" for="to">Hasta</label><input id="to" name="to" type="date" value="{{ $f['to'] ?? '' }}" class="snow-input"></div>
            <div class="col-span-2 flex gap-2 sm:col-span-4 lg:col-span-8">
                <button type="submit" class="snow-btn-primary">Filtrar</button>
                <a href="{{ route('admin.audit.index') }}" class="snow-btn-secondary">Limpiar</a>
                <a href="{{ $exportUrl }}" class="snow-btn-secondary">Exportar CSV</a>
                <span class="ml-auto self-center text-xs text-slate-500">{{ number_format($logs->total()) }} registros</span>
            </div>
            @if($errors->any())<p class="col-span-full text-xs text-red-600">{{ $errors->first() }}</p>@endif
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-[1000px]">
                <thead><tr>
                    <th class="text-left">Fecha y hora</th><th class="text-left">Usuario</th><th class="text-left">Movimiento</th>
                    <th class="text-left">Módulo</th><th class="text-left">Cambios (antes → después)</th><th class="w-10"></th>
                </tr></thead>
                @forelse($logs as $log)
                    @php $changes = collect($log->changes ?? []); @endphp
                    <tbody x-data="{ open: false }" class="border-t border-slate-100">
                        <tr class="align-top hover:bg-slate-50">
                            <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                                <span class="block font-medium text-slate-800">{{ $log->created_at?->format('d/m/Y') }}</span>
                                <span class="text-xs text-slate-500">{{ $log->created_at?->format('H:i:s') }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="block font-medium text-slate-800">{{ $log->admin_name ?? 'Sistema' }}</span>
                                <span class="text-xs text-slate-500">{{ $log->ip ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="snow-tag {{ $tag[$log->action] ?? 'bg-slate-100 text-slate-700' }}">{{ \App\Support\AuditLabels::action($log->action) }}</span>
                                <span class="mt-1 block max-w-[28rem] text-slate-700">{{ $log->summary ?? \App\Support\AuditLabels::describeChange($log->action, $log->entity_type) }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <span class="block text-slate-800">{{ \App\Support\AuditLabels::entity($log->entity_type) }}</span>
                                @if($log->entity_id)<span class="block max-w-[11rem] truncate font-mono text-xs text-slate-500" title="{{ $log->entity_id }}">{{ $log->entity_id }}</span>@endif
                            </td>
                            <td class="px-3 py-2">
                                @forelse($changes->take(3) as $field => $pair)
                                    <div class="text-xs">
                                        <span class="font-medium text-slate-700">{{ $field }}:</span>
                                        <span class="text-red-700 line-through decoration-red-300">{{ \Illuminate\Support\Str::limit($val($pair[0] ?? null), 28) }}</span>
                                        <span aria-hidden="true">→</span>
                                        <span class="text-green-700">{{ \Illuminate\Support\Str::limit($val($pair[1] ?? null), 28) }}</span>
                                    </div>
                                @empty
                                    <span class="text-xs text-slate-400">{{ in_array($log->action, ['view', 'submit'], true) ? ($log->input ? 'Con datos enviados' : '—') : '—' }}</span>
                                @endforelse
                                @if($changes->count() > 3)<span class="text-xs text-slate-500">+ {{ $changes->count() - 3 }} campos más</span>@endif
                            </td>
                            <td class="px-2 py-2 text-right">
                                <button type="button" class="snow-icon-btn h-6 w-6" x-on:click="open = !open" :aria-expanded="open ? 'true' : 'false'" title="Ver detalle">
                                    <x-admin.snow.icon name="chevron-down" class="h-4 w-4 transition" x-bind:class="open ? 'rotate-180' : ''" />
                                </button>
                            </td>
                        </tr>
                        <tr x-show="open" x-cloak class="bg-slate-50">
                            <td colspan="6" class="px-4 py-3">
                                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                    <div>
                                        <h3 class="mb-1 text-xs font-bold uppercase tracking-[0.04em] text-slate-500">{{ $log->action === 'created' ? 'Datos al crear' : ($log->action === 'deleted' ? 'Datos eliminados' : 'Antes y después') }}</h3>
                                        @if($changes->isNotEmpty())
                                            <table class="w-full text-left text-xs">
                                                <thead class="snow-table-head"><tr><th class="px-2 py-1">Campo</th><th class="px-2 py-1">Antes</th><th class="px-2 py-1">Después</th></tr></thead>
                                                <tbody class="divide-y divide-slate-100">
                                                    @foreach($changes as $field => $pair)
                                                        <tr><td class="px-2 py-1 font-medium text-slate-700">{{ $field }}</td><td class="break-all px-2 py-1 text-red-700">{{ $val($pair[0] ?? null) }}</td><td class="break-all px-2 py-1 text-green-700">{{ $val($pair[1] ?? null) }}</td></tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @else
                                            <p class="text-xs text-slate-500">Este movimiento no modificó datos.</p>
                                        @endif
                                    </div>
                                    <div>
                                        <h3 class="mb-1 text-xs font-bold uppercase tracking-[0.04em] text-slate-500">Detalle técnico</h3>
                                        <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1 text-xs">
                                            <dt class="text-slate-500">Ruta</dt><dd class="break-all text-slate-800">{{ $log->route ?? '—' }}</dd>
                                            <dt class="text-slate-500">Método</dt><dd class="text-slate-800">{{ $log->method ?? '—' }}</dd>
                                            <dt class="text-slate-500">Dirección</dt><dd class="break-all text-slate-800">{{ $log->url ?? '—' }}</dd>
                                            <dt class="text-slate-500">Resultado</dt><dd class="text-slate-800">{{ $log->status_code ?? '—' }}</dd>
                                            <dt class="text-slate-500">Navegador</dt><dd class="break-all text-slate-800">{{ $log->user_agent ?? '—' }}</dd>
                                            <dt class="text-slate-500">Id del registro</dt><dd class="break-all font-mono text-slate-800">{{ $log->entity_id ?? '—' }}</dd>
                                            <dt class="text-slate-500">Datos enviados</dt>
                                            <dd>@if($log->input)<pre class="max-h-40 overflow-auto rounded border border-slate-200 bg-white p-2 text-[11px] text-slate-700">{{ json_encode($log->input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@else<span class="text-slate-800">—</span>@endif</dd>
                                        </dl>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody><tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No hay movimientos con esos filtros.</td></tr></tbody>
                @endforelse
            </table>
        </div>
        <div class="border-t border-slate-200 p-3">{{ $logs->links() }}</div>
    </div>
</x-admin.layouts.app>
