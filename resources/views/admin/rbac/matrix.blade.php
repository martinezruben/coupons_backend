@php
    $assignedSet = array_flip($assigned ?? []);
@endphp

<x-admin.layouts.app title="Permisos por rol">
    <div class="snow-card overflow-hidden">
        <div class="border-b border-slate-100 bg-slate-50/80 px-3 py-2 sm:px-4">
            <h1 class="text-sm font-semibold text-slate-900">Permisos por rol y pantalla</h1>
            <p class="mt-0.5 text-[10px] text-slate-500">
                Marca qué puede hacer cada rol en cada pantalla del panel. Los cambios se guardan en la base de datos (Spatie).
            </p>
        </div>

        <form method="POST" action="{{ route('admin.rbac.matrix.update', $role) }}" class="p-3 sm:p-4">
            @csrf

            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <label for="rbac-role" class="text-[8px] font-mono font-bold uppercase tracking-widest text-slate-500">Rol</label>
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
                <table class="w-full min-w-[640px] border-collapse text-left text-[10px]">
                    <thead class="border-b border-slate-100 bg-slate-50/95">
                        <tr class="snow-table-head">
                            <th class="sticky left-0 z-10 bg-slate-50/95 px-2 py-1.5 font-semibold">Pantalla</th>
                            <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold">Ver</th>
                            <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold">Editar</th>
                            <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold">Eliminar</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach($screenGroups as $group)
                            <tr class="bg-slate-50/80">
                                <td colspan="4" class="sticky left-0 z-[1] bg-slate-50/95 px-2 py-1 text-[9px] font-mono font-bold uppercase tracking-widest text-slate-500 shadow-[1px_0_0_0_rgb(241_245_249)]">
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
                                        <span class="text-[9px] font-normal text-slate-500">(solo lectura)</span>
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
                            </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-[10px] text-slate-500">
                Los permisos de este formulario sustituyen solo las casillas de la tabla; otros permisos del rol que no estén en la matriz se mantienen.
            </p>
        </form>
    </div>
</x-admin.layouts.app>
