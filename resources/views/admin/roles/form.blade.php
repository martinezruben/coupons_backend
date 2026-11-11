<x-admin.layouts.app :title="$role ? 'Renombrar rol' : 'Nuevo rol'">
    <div class="snow-card max-w-xl">
        <div class="border-b border-slate-100 bg-slate-50/80 px-4 py-2.5">
            <h1 class="text-sm font-bold text-slate-900">{{ $role ? 'Renombrar rol «'.$role->name.'»' : 'Nuevo rol' }}</h1>
            <p class="mt-0.5 text-[11px] text-slate-500">
                @if ($role)
                    Los usuarios y los permisos del rol se conservan.
                @else
                    Después de crearlo marcarás sus permisos en la matriz.
                @endif
            </p>
        </div>
        <form method="POST" action="{{ $role ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="flex flex-col gap-3 px-4 py-4">
            @csrf
            @if ($role)
                @method('PUT')
            @endif

            <div>
                <label class="snow-label" for="f-name">Nombre del rol</label>
                <input id="f-name" type="text" name="name" value="{{ old('name', $role?->name) }}" required minlength="3" maxlength="50" placeholder="gerente-sucursal" class="snow-input max-w-sm" autocomplete="off">
                <p class="mt-1 text-[11px] text-slate-500">Minúsculas, números y guiones. Ejemplos: <span class="font-mono">gerente</span>, <span class="font-mono">soporte-tecnico</span>.</p>
                @error('name')
                    <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                @enderror
            </div>

            @unless ($role)
                <div>
                    <label class="snow-label" for="f-copy">Copiar permisos de (opcional)</label>
                    <select id="f-copy" name="copy_from" class="snow-input max-w-sm">
                        <option value="">— Empezar sin permisos —</option>
                        @foreach ($copyFrom as $source)
                            <option value="{{ $source->id }}" @selected((string) old('copy_from') === (string) $source->id)>{{ $source->name }}</option>
                        @endforeach
                    </select>
                    @error('copy_from')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endunless

            <div class="flex flex-wrap items-center gap-2 pt-1">
                <button type="submit" class="snow-btn-primary">{{ $role ? 'Guardar' : 'Crear rol' }}</button>
                <a href="{{ route('admin.roles.index') }}" class="snow-btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</x-admin.layouts.app>
