<x-admin.layouts.app title="Roles">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-slate-500">Un rol agrupa permisos y límites; a cada usuario administrador se le asignan uno o varios.</p>
        <a href="{{ route('admin.roles.create') }}" class="snow-btn-primary">Nuevo rol</a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @forelse ($roles as $role)
            <div class="snow-card flex flex-col">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 class="text-base font-medium text-slate-800">
                            {{ $role->name }}
                            @if ($role->is_protected)
                                <span class="snow-tag ml-1 bg-primary-50 text-primary-800" title="Acceso total; no se puede modificar">Super-admin</span>
                            @endif
                        </h2>
                        <p class="text-sm text-slate-500">{{ $role->users_count }} {{ $role->users_count === 1 ? 'cuenta' : 'cuentas' }} · {{ $role->is_protected ? 'todos los permisos' : $role->permissions_count.' permisos' }}</p>
                    </div>
                    @unless ($role->is_protected)
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('admin.rbac.matrix.edit', $role) }}" class="snow-btn-secondary snow-btn-sm">Permisos y cuentas</a>
                            <a href="{{ route('admin.roles.edit', $role) }}" class="snow-btn-secondary snow-btn-sm">Renombrar</a>
                            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="inline" onsubmit="return confirm('¿Eliminar el rol «{{ $role->name }}»?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="snow-btn-secondary snow-btn-sm" @disabled($role->users_count > 0) title="{{ $role->users_count > 0 ? 'Tiene usuarios asignados' : 'Eliminar rol' }}">Eliminar</button>
                            </form>
                        </div>
                    @endunless
                </div>
                <div class="flex-1 space-y-4 p-5">
                    @unless ($role->is_protected)
                        <div>
                            <div class="subheader mb-2">Módulos</div>
                            <div class="flex flex-wrap gap-1.5">
                                @forelse ($role->modules as $module)
                                    <span class="snow-tag bg-slate-100 text-slate-700" title="{{ $module['count'] }} permisos">{{ $module['label'] }} · {{ $module['count'] }}</span>
                                @empty
                                    <span class="text-sm text-slate-500">Sin permisos todavía.</span>
                                @endforelse
                            </div>
                        </div>
                    @endunless
                    <div>
                        <div class="subheader mb-2">Cuentas</div>
                        <div class="flex flex-wrap items-center gap-2">
                            @forelse ($role->members as $member)
                                <span class="avatar" title="{{ $member->name }}">{{ collect(explode(' ', $member->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
                            @empty
                                <span class="text-sm text-slate-500">Nadie tiene este rol.</span>
                            @endforelse
                            @if ($role->users_count > $role->members->count())
                                <span class="text-sm text-slate-500">+{{ $role->users_count - $role->members->count() }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="snow-card p-5 text-center text-sm text-slate-500 lg:col-span-2">Todavía no hay roles. Crea el primero.</div>
        @endforelse
    </div>
</x-admin.layouts.app>
