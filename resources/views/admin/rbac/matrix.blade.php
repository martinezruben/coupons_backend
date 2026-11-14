@php
    $assignedSet = array_flip($assigned ?? []);
@endphp

<x-admin.layouts.app title="Permisos por rol">
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-100 bg-slate-50/80 px-3 py-2 sm:px-4">
            <h2 class="text-base font-medium text-slate-800">Permisos por rol y pantalla</h2>
            <p class="mt-0.5 text-xs text-slate-500">
                Marca qué puede hacer cada rol en cada pantalla del panel. Los cambios se guardan en la base de datos (Spatie).
            </p>
        </div>

        <form method="POST" action="{{ route('admin.rbac.matrix.update', $role) }}" class="p-3 sm:p-4">
            @csrf

            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <label for="rbac-role" class="text-2xs font-bold uppercase tracking-[0.04em] text-slate-500">Rol</label>
                    <select
                        id="rbac-role"
                        class="hope-filter-select max-w-md py-1.5 text-xs"
                        onchange="if (this.value) window.location.href = this.value"
                    >
                        @foreach($roles as $r)
                            <option
                                value="{{ route('admin.rbac.matrix.edit', $r) }}"
                                @selected($r->getKey() === $role->getKey())
                            >{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.roles.index') }}" class="snow-btn-secondary">Gestionar roles</a>
                    <button
                        type="submit"
                        class="inline-flex shrink-0 items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-700"
                    >
                        Guardar permisos
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg border border-slate-200">
                <table class="w-full min-w-[640px] border-collapse text-left text-xs">
                    <thead class="border-b border-slate-100 bg-slate-50/95">
                        <tr class="snow-table-head">
                            <th class="sticky left-0 z-10 bg-slate-50/95 px-2 py-1.5 font-semibold">Pantalla</th>
                            <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold">Ver</th>
                            <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold">Editar</th>
                            <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold">Eliminar</th>
                            <th class="whitespace-nowrap px-2 py-1.5 font-semibold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach($screenGroups as $group)
                            <tr class="bg-slate-50/80">
                                <td colspan="5" class="sticky left-0 z-[1] bg-slate-50/95 px-2 py-1 text-2xs font-bold uppercase tracking-[0.04em] text-slate-500 shadow-[1px_0_0_0_rgb(241_245_249)]">
                                    {{ $group['label'] }}
                                </td>
                            </tr>
                            @foreach($group['screens'] as $s)
                            @php
                                $p = \App\Support\AdminRbac::permissionsForScreen($s['key']);
                                $ro = $s['readonly'];
                            @endphp
                            <tr class="hover:bg-slate-50/80">
                                <td class="sticky left-0 z-[1] bg-white px-2 py-1 align-middle font-medium text-slate-800 shadow-[1px_0_0_0_rgb(241_245_249)]">
                                    <span class="block truncate" title="{{ $s['key'] }}">{{ $s['label'] }}</span>
                                    @if($ro)
                                        <span class="text-2xs font-normal text-slate-500">(solo lectura)</span>
                                    @endif
                                </td>
                                <td class="px-2 py-1 text-center align-middle">
                                    <input
                                        type="checkbox"
                                        name="permissions[]"
                                        value="{{ $p['view'] }}"
                                        class="h-3.5 w-3.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                                        @checked(isset($assignedSet[$p['view']]))
                                    >
                                </td>
                                <td class="px-2 py-1 text-center align-middle">
                                    @if($ro)
                                        <span class="text-slate-300">—</span>
                                    @else
                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="{{ $p['edit'] }}"
                                            class="h-3.5 w-3.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                                            @checked(isset($assignedSet[$p['edit']]))
                                        >
                                    @endif
                                </td>
                                <td class="px-2 py-1 text-center align-middle">
                                    @if($ro)
                                        <span class="text-slate-300">—</span>
                                    @else
                                        <input
                                            type="checkbox"
                                            name="permissions[]"
                                            value="{{ $p['delete'] }}"
                                            class="h-3.5 w-3.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                                            @checked(isset($assignedSet[$p['delete']]))
                                        >
                                    @endif
                                </td>
                                <td class="px-2 py-1 align-middle">
                                    @forelse(\App\Support\AdminRbac::actionPermissions($s['key']) as $action)
                                        <label class="mr-2 inline-flex items-center gap-1 whitespace-nowrap">
                                            <input
                                                type="checkbox"
                                                name="permissions[]"
                                                value="{{ $action['permission'] }}"
                                                class="h-3.5 w-3.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
                                                @checked(isset($assignedSet[$action['permission']]))
                                            >
                                            <span>{{ $action['label'] }}</span>
                                        </label>
                                    @empty
                                        <span class="text-slate-300">—</span>
                                    @endforelse
                                </td>
                            </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-6 rounded-lg border border-slate-200 p-4">
                <h3 class="text-base font-medium text-slate-800">Límites del rol</h3>
                <p class="mt-0.5 text-sm text-slate-500">Vacío = sin límite propio (vale el máximo del sistema). Si una persona tiene varios roles se aplica el más permisivo.</p>
                <div class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <label class="snow-label" for="lim-act">Máx. cupones por activación</label>
                        <input id="lim-act" name="limits[max_activation_size]" type="number" min="1" max="{{ config('coupons.max_activation_size') }}" value="{{ old('limits.max_activation_size', $limits?->max_activation_size) }}" class="snow-input" placeholder="Sin límite">
                        @error('limits.max_activation_size')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="snow-label" for="lim-qty">Máx. cupones por lote</label>
                        <input id="lim-qty" name="limits[max_batch_quantity]" type="number" min="1" max="20000" value="{{ old('limits.max_batch_quantity', $limits?->max_batch_quantity) }}" class="snow-input" placeholder="Sin límite">
                        @error('limits.max_batch_quantity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="snow-label" for="lim-val">Valor máximo por lote (cantidad × monto)</label>
                        <input id="lim-val" name="limits[max_batch_value]" type="number" step="0.01" min="0.01" value="{{ old('limits.max_batch_value', $limits?->max_batch_value !== null ? number_format((float) $limits->max_batch_value, 2, '.', '') : null) }}" class="snow-input" placeholder="Sin límite">
                        @error('limits.max_batch_value')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>

            <p class="mt-3 text-xs text-slate-500">
                Los permisos de este formulario sustituyen solo las casillas de la tabla; otros permisos del rol que no estén en la matriz se mantienen.
            </p>
        </form>

        <div class="border-t border-slate-200 p-3 sm:p-4">
            <h3 class="text-base font-medium text-slate-800">Cuentas con este rol</h3>
            <p class="mt-0.5 text-sm text-slate-500">Quién usa este perfil. Los cambios aplican de inmediato y quedan en la auditoría.</p>
            @error('role_names')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            @error('is_active')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                @forelse($accounts as $account)
                    <li class="flex items-center justify-between gap-3 px-3 py-2">
                        <span class="flex min-w-0 items-center gap-3">
                            <span class="avatar shrink-0">{{ collect(explode(' ', $account->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-slate-800">{{ $account->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $account->email }} · {{ $account->customer_scope === 'selected' ? 'solo clientes seleccionados' : 'todos los clientes' }}@unless($account->is_active) · inactiva @endunless</span>
                            </span>
                        </span>
                        <form method="POST" action="{{ route('admin.rbac.accounts.remove', [$role, $account]) }}" onsubmit="return confirm('¿Quitar el rol «{{ $role->name }}» a {{ $account->name }}?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="snow-btn-secondary snow-btn-sm">Quitar</button>
                        </form>
                    </li>
                @empty
                    <li class="px-3 py-4 text-center text-sm text-slate-500">Todavía nadie tiene este rol.</li>
                @endforelse
            </ul>
            <form method="POST" action="{{ route('admin.rbac.accounts.add', $role) }}" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                <div class="min-w-[16rem] flex-1 sm:max-w-md">
                    <label class="snow-label" for="add-account">Dar este rol a</label>
                    <select id="add-account" name="admin_user_id" class="snow-input" required>
                        <option value="">— Seleccionar cuenta —</option>
                        @foreach($candidates as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->email }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="snow-btn-primary">Añadir</button>
            </form>
        </div>
    </div>
</x-admin.layouts.app>
