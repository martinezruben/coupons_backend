@php
    $directSet = array_flip($direct);
    $cell = function (string $perm) use ($directSet, $viaRole) {
        return ['direct' => isset($directSet[$perm]), 'roles' => $viaRole[$perm] ?? []];
    };
@endphp
<x-admin.layouts.app :title="'Permisos de '.$target->name">
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <h2 class="text-base font-medium text-slate-800">Permisos específicos de {{ $target->name }}</h2>
            <p class="mt-0.5 text-sm text-slate-500">
                Las casillas <span class="rounded bg-slate-200 px-1">grises</span> ya vienen de sus roles ({{ $target->roles->pluck('name')->implode(', ') ?: 'sin roles' }}); marca las demás para darle permisos adicionales solo a esta persona.
                Un permiso de rol no se quita aquí: se quita del rol.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.rbac.users.update', $target) }}" class="p-3 sm:p-4">
            @csrf
            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <label for="rbac-user" class="snow-label">Usuario</label>
                    <select id="rbac-user" class="snow-input max-w-md" onchange="if (this.value) window.location.href = this.value">
                        @foreach($users as $u)
                            @continue($u->isSuperAdmin())
                            <option value="{{ route('admin.rbac.users.edit', $u) }}" @selected($u->is($target))>{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('admin.rbac.users.index') }}" class="snow-btn-secondary">Todos los usuarios</a>
                    <button type="submit" class="snow-btn-primary">Guardar permisos</button>
                </div>
            </div>
            @error('permissions.*')<p class="mb-2 text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="mb-4 rounded-lg border border-slate-200">
                <div class="border-b border-slate-200 bg-slate-50 px-3 py-2">
                    <h3 class="text-sm font-medium text-slate-800">Permisos independientes</h3>
                    <p class="text-xs text-slate-500">Acciones puntuales que se dan una por una, sin dar acceso a editar la pantalla. Las marcadas como <span class="snow-tag bg-amber-50 text-amber-800">sensible</span> mueven dinero o sacan información.</p>
                </div>
                <div class="grid grid-cols-1 gap-x-6 gap-y-1 p-3 md:grid-cols-2">
                    @foreach($independent as $groupLabel => $items)
                        <div class="md:col-span-2 mt-2 text-2xs font-bold uppercase tracking-[0.04em] text-slate-500 first:mt-0">{{ $groupLabel }}</div>
                        @foreach($items as $it)
                            @php $c = $cell($it['permission']); @endphp
                            <label class="flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-slate-50" @if($c['roles']) title="Viene del rol: {{ implode(', ', $c['roles']) }}" @endif>
                                <input type="checkbox" name="permissions[]" value="{{ $it['permission'] }}" @checked($c['direct'] || $c['roles']) @disabled((bool) $c['roles'])
                                       class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500 {{ $c['roles'] ? 'opacity-50' : '' }}">
                                @if($c['roles'] && $c['direct'])<input type="hidden" name="permissions[]" value="{{ $it['permission'] }}">@endif
                                <span class="{{ $c['roles'] ? 'text-slate-400' : 'text-slate-800' }}"><strong class="font-medium">{{ $it['label'] }}</strong> <span class="text-slate-500">· {{ $it['screen'] }}</span></span>
                                @if($it['sensitive'])<span class="snow-tag bg-amber-50 text-amber-800">sensible</span>@endif
                            </label>
                        @endforeach
                    @endforeach
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="w-full min-w-[640px] border-collapse text-left text-xs">
                    <thead class="border-b border-slate-100 bg-slate-50/95">
                        <tr class="snow-table-head">
                            <th class="px-2 py-1.5 font-semibold">Pantalla</th>
                            <th class="px-2 py-1.5 text-center font-semibold">Ver</th>
                            <th class="px-2 py-1.5 text-center font-semibold">Editar</th>
                            <th class="px-2 py-1.5 text-center font-semibold">Eliminar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach($screenGroups as $group)
                        <tr class="bg-slate-50/80"><td colspan="4" class="px-2 py-1 text-2xs font-bold uppercase tracking-[0.04em] text-slate-500">{{ $group['label'] }}</td></tr>
                        @foreach($group['screens'] as $s)
                            @php $p = \App\Support\AdminRbac::permissionsForScreen($s['key']); $ro = $s['readonly']; @endphp
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-2 py-1 font-medium text-slate-800">{{ $s['label'] }}@if($ro)<span class="text-2xs font-normal text-slate-500"> (solo lectura)</span>@endif</td>
                                @foreach(['view', 'edit', 'delete'] as $k)
                                    <td class="px-2 py-1 text-center">
                                        @if($ro && $k !== 'view')
                                            <span class="text-slate-300">—</span>
                                        @else
                                            @php $c = $cell($p[$k]); @endphp
                                            <input type="checkbox" name="permissions[]" value="{{ $p[$k] }}" @checked($c['direct'] || $c['roles']) @disabled((bool) $c['roles'])
                                                   @if($c['roles']) title="Viene del rol: {{ implode(', ', $c['roles']) }}" @endif
                                                   class="h-3.5 w-3.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500 {{ $c['roles'] ? 'opacity-50' : '' }}">
                                            @if($c['roles'] && $c['direct'])<input type="hidden" name="permissions[]" value="{{ $p[$k] }}">@endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-500">Guarda solo las casillas de esta tabla; cualquier otro permiso directo que la persona tenga se conserva.</p>
        </form>
    </div>
</x-admin.layouts.app>
