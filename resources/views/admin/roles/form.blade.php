<x-admin.layouts.app :title="$role ? 'Renombrar rol' : 'Nuevo rol'">
    <section class="flex flex-wrap items-start gap-6">
        <form method="POST" action="{{ $role ? route('admin.roles.update', $role) : route('admin.roles.store') }}" class="snow-card flex max-w-[720px] flex-[1_1_520px] flex-col gap-5 p-7">
            @csrf
            @if ($role)@method('PUT')@endif
            <div>
                <h2 class="!text-2xl !font-bold">{{ $role ? 'Renombrar rol «'.$role->name.'»' : 'Datos del rol' }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ $role ? 'Los usuarios y los permisos del rol se conservan.' : 'Después de crearlo marcarás sus permisos en la matriz.' }}</p>
            </div>

            <div>
                <label class="snow-label" for="f-name">Nombre del rol</label>
                <input id="f-name" type="text" name="name" value="{{ old('name', $role?->name) }}" required minlength="3" maxlength="50" placeholder="coordinador-sucursal" class="snow-input font-mono" autocomplete="off" autofocus>
                <p class="mt-1.5 text-[13px] text-slate-500">Minúsculas, números y guiones. Ejemplos: <span class="font-mono">gerente</span>, <span class="font-mono">soporte-tecnico</span>.</p>
                @error('name')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            </div>

            @unless ($role)
                <div>
                    <label class="snow-label" for="f-copy">Copiar permisos de (opcional)</label>
                    <select id="f-copy" name="copy_from" class="snow-input">
                        <option value="">— Empezar sin permisos —</option>
                        @foreach ($copyFrom as $source)
                            <option value="{{ $source->id }}" @selected((string) old('copy_from') === (string) $source->id)>{{ $source->name }}</option>
                        @endforeach
                    </select>
                    @error('copy_from')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                </div>
            @endunless

            <div class="flex flex-wrap gap-2.5">
                <button type="submit" class="snow-btn-primary">{{ $role ? 'Guardar' : 'Crear rol' }}</button>
                <a href="{{ route('admin.roles.index') }}" class="snow-btn-secondary">Cancelar</a>
            </div>
        </form>

        <aside class="snow-card flex min-w-[280px] flex-[0_1_340px] flex-col gap-5 p-6">
            <span class="eyebrow">Cómo funciona</span>
            <ol class="flex flex-col gap-4">
                <li class="flex items-start gap-3"><span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-primary-600 font-mono text-sm font-bold text-lime-300">1</span><span><strong>Crea el rol</strong><br><span class="text-sm text-slate-600">Ponle un nombre claro, por puesto o función.</span></span></li>
                <li class="flex items-start gap-3"><span class="flex h-8 w-8 flex-none items-center justify-center rounded-full border-2 border-slate-300 font-mono text-sm font-bold text-slate-600">2</span><span><strong>Marca sus permisos</strong><br><span class="text-sm text-slate-600">Elige qué ve, edita y elimina en cada pantalla.</span></span></li>
            </ol>
            <div class="perf" style="margin: 0"></div>
            <p class="text-sm text-slate-600"><strong>Un usuario, un rol.</strong> Cada usuario administrador tiene un solo rol, así que define el rol pensando en el puesto completo.</p>
        </aside>
    </section>
</x-admin.layouts.app>
