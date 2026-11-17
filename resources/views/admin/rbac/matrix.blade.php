@php
    $assignedSet = array_flip($assigned ?? []);
    $anchors = ['customers' => 'g-clientes', 'coupons' => 'g-cupones', 'reports' => 'g-reportes', 'infra' => 'g-infra', 'system' => 'g-conf'];
    $chip = fn (string $perm) => isset($assignedSet[$perm]);
@endphp

<x-admin.layouts.app title="Permisos por rol">
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <p class="max-w-[560px] text-[15px] text-slate-600">Marca qué puede hacer cada rol en cada pantalla del panel.</p>
            <a href="{{ route('admin.roles.create') }}" class="snow-btn-secondary">Nuevo rol</a>
        </div>

        <form method="POST" action="{{ route('admin.rbac.matrix.update', $role) }}" class="flex flex-col gap-6">
            @csrf
            <section class="snow-card flex flex-wrap items-end justify-between gap-4 px-6 py-4">
                <div class="min-w-[220px] flex-[0_1_320px]">
                    <label for="rbac-role" class="snow-label">Rol</label>
                    <select id="rbac-role" class="snow-input" onchange="if (this.value) window.location.href = this.value">
                        @foreach($roles as $r)
                            <option value="{{ route('admin.rbac.matrix.edit', $r) }}" @selected($r->getKey() === $role->getKey())>{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <a href="{{ route('admin.roles.index') }}" class="snow-btn-secondary">Gestionar roles</a>
                    <button type="submit" class="snow-btn-primary">Guardar permisos</button>
                </div>
            </section>

            <section class="flex flex-wrap items-start gap-6">
                <nav aria-label="Grupos de pantallas" class="snow-card flex flex-[0_0_230px] flex-col gap-0.5 p-2.5 lg:sticky lg:top-24">
                    <span class="eyebrow px-3.5 pb-1.5 pt-2.5">Pantallas por grupo</span>
                    @foreach($screenGroups as $i => $group)
                        <a href="#g-{{ $i }}" class="flex min-h-[44px] items-center justify-between rounded-xl px-3.5 font-medium text-slate-900 no-underline hover:bg-slate-100">{{ $group['label'] }}<span class="font-mono text-xs text-slate-500">{{ count($group['screens']) }}</span></a>
                    @endforeach
                </nav>

                <div class="flex min-w-0 flex-[1_1_640px] flex-col gap-5">
                    @foreach($screenGroups as $i => $group)
                        <section id="g-{{ $i }}" class="snow-card px-6 pb-2 pt-5" x-data>
                            <div class="flex items-center justify-between gap-3 pb-3">
                                <h2 class="!text-2xl !font-bold">{{ $group['label'] }}</h2>
                                <button type="button" class="snow-btn-ghost snow-btn-sm" x-on:click="const boxes = $el.closest('section').querySelectorAll('input[type=checkbox]'); const all = [...boxes].every(b => b.checked); boxes.forEach(b => b.checked = !all)">Marcar todo</button>
                            </div>
                            @foreach($group['screens'] as $s)
                                @php $p = \App\Support\AdminRbac::permissionsForScreen($s['key']); $ro = $s['readonly']; $actions = \App\Support\AdminRbac::actionPermissions($s['key']); @endphp
                                <div class="flex flex-wrap gap-x-7 gap-y-3 border-t-[1.5px] border-slate-100 py-4">
                                    <div class="flex-[0_0_230px] pt-2 font-semibold">{{ $s['label'] }}@if($ro)<span class="b b-mute ml-2 !text-[11px]">solo lectura</span>@endif</div>
                                    <div class="min-w-0 flex-[1_1_420px]">
                                        <div class="flex flex-wrap gap-2">
                                            <label class="chip"><input type="checkbox" name="permissions[]" value="{{ $p['view'] }}" @checked($chip($p['view']))>Ver</label>
                                            @unless($ro)
                                                <label class="chip"><input type="checkbox" name="permissions[]" value="{{ $p['edit'] }}" @checked($chip($p['edit']))>Editar</label>
                                                <label class="chip"><input type="checkbox" name="permissions[]" value="{{ $p['delete'] }}" @checked($chip($p['delete']))>Eliminar</label>
                                            @endunless
                                        </div>
                                        @if($actions)
                                            <div class="mt-2.5 flex flex-wrap items-center gap-2">
                                                <span class="eyebrow mr-1">Acciones</span>
                                                @foreach($actions as $action)
                                                    <label class="chip"><input type="checkbox" name="permissions[]" value="{{ $action['permission'] }}" @checked($chip($action['permission']))>{{ $action['label'] }}</label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </section>
                    @endforeach

                    <section class="snow-card px-7 py-6">
                        <h2>Límites del rol</h2>
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
                    </section>

                    <p class="text-xs text-slate-500">Los permisos de este formulario sustituyen solo las casillas de las tarjetas; otros permisos del rol que no estén en la matriz se mantienen.</p>
                </div>
            </section>
        </form>

        <section class="snow-card px-7 py-6">
            <h2>Cuentas con este rol</h2>
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
                        <a href="{{ route('admin.rbac.users.edit', $account) }}" class="snow-btn-secondary snow-btn-sm ml-auto">Permisos propios</a>
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
        </section>
    </div>
</x-admin.layouts.app>
