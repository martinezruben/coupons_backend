<x-admin.layouts.app title="Roles">
    <div class="snow-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50/80 px-3 py-2 sm:px-4">
            <div>
                <h1 class="text-sm font-semibold text-slate-900">Roles del panel</h1>
                <p class="mt-0.5 text-[11px] text-slate-500">Un rol agrupa permisos; a cada usuario administrador se le asignan uno o varios.</p>
            </div>
            <a href="{{ route('admin.roles.create') }}" class="snow-btn-primary">Nuevo rol</a>
        </div>

        @if ($errors->any())
            <div class="mx-3 mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 sm:mx-4" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100 text-left text-xs">
                <thead class="snow-table-head">
                    <tr>
                        <th class="px-4 py-2 font-semibold">Rol</th>
                        <th class="px-4 py-2 text-right font-semibold">Usuarios</th>
                        <th class="px-4 py-2 text-right font-semibold">Permisos</th>
                        <th class="px-4 py-2 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse ($roles as $role)
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-4 py-2.5 font-medium text-slate-800">
                                {{ $role->name }}
                                @if ($role->is_protected)
                                    <span class="snow-tag ml-1 bg-primary-50 text-primary-800" title="Acceso total; no se puede modificar">Super-admin</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $role->users_count }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $role->is_protected ? 'Todos' : $role->permissions_count }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                @unless ($role->is_protected)
                                    <a href="{{ route('admin.rbac.matrix.edit', $role) }}" class="snow-btn-secondary">Permisos</a>
                                    <a href="{{ route('admin.roles.edit', $role) }}" class="snow-btn-secondary">Renombrar</a>
                                    <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="inline" onsubmit="return confirm('¿Eliminar el rol «{{ $role->name }}»?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="snow-btn-danger" @disabled($role->users_count > 0) title="{{ $role->users_count > 0 ? 'Tiene usuarios asignados' : 'Eliminar rol' }}">Eliminar</button>
                                    </form>
                                @else
                                    <span class="text-[11px] text-slate-500">—</span>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-center text-xs text-slate-500">Todavía no hay roles. Crea el primero.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layouts.app>
