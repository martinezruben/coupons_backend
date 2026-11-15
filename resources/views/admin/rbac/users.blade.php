<x-admin.layouts.app title="Permisos por usuario">
    <div class="snow-card">
        <div class="border-b border-slate-200 bg-slate-100 px-5 py-3">
            <p class="text-sm text-slate-500">
                Además de los permisos de sus roles, a cada persona se le pueden dar <strong>permisos específicos</strong>. Úsalo para excepciones puntuales sin crear un rol nuevo.
                Todo cambio queda en la auditoría.
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-[720px]">
                <thead><tr><th class="text-left">Usuario</th><th class="text-left">Roles</th><th class="text-right">Permisos específicos</th><th class="w-32"></th></tr></thead>
                <tbody>
                @foreach($users as $u)
                    <tr class="hover:bg-slate-50">
                        <td class="px-3 py-2">
                            <span class="block font-medium text-slate-800">{{ $u->name }}</span>
                            <span class="text-xs text-slate-500">{{ $u->email }}@unless($u->is_active) · inactivo @endunless</span>
                        </td>
                        <td class="px-3 py-2">
                            @forelse($u->roles as $r)<span class="snow-tag mr-1 bg-slate-100 text-slate-700">{{ $r->name }}</span>@empty<span class="text-xs text-slate-400">Sin roles</span>@endforelse
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $u->isSuperAdmin() ? 'todos (super-admin)' : ($direct[$u->id] ?? 0) }}</td>
                        <td class="px-3 py-2 text-right">
                            @unless($u->isSuperAdmin())<a href="{{ route('admin.rbac.users.edit', $u) }}" class="snow-btn-secondary snow-btn-sm">Permisos</a>@endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-admin.layouts.app>
