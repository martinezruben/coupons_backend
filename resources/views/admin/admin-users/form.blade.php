@php
    $isSelf = $item && auth('admin')->id() === $item->getKey();
    $currentRoles = $item ? $item->roles->pluck('name')->all() : [];
    $selectedRole = old('role_names.0', $currentRoles[0] ?? '');
    $scope = old('customer_scope', $item->customer_scope ?? 'all');
    $scopeIds = old('customer_ids', $item ? $item->customers()->pluck('customers.id')->all() : []);
    $isActive = (bool) old('is_active', $item ? $item->is_active : true);
    $minLen = max(14, (int) (\App\Models\SystemParameter::query()->value('admin_password_min_length') ?? 8));
    $initial = mb_strtoupper(mb_substr($item?->name ?? 'N', 0, 1));
    $title = $item ? 'Editar usuario administrador' : 'Nuevo usuario administrador';
@endphp
<x-admin.layouts.app title="Usuarios administradores">
    <div class="flex flex-col gap-6">
        <p class="max-w-[560px] text-[15px] text-slate-600">{{ $title }}: completa los campos y guarda.</p>

        <section class="flex flex-wrap items-start gap-6">
            <aside class="snow-card flex min-w-[280px] flex-[0_1_320px] flex-col items-start gap-4 p-7">
                <span class="flex h-[72px] w-[72px] items-center justify-center rounded-full bg-lime-300 font-display text-3xl font-bold text-primary-600">{{ $initial }}</span>
                <div>
                    <div class="font-display text-2xl font-bold">{{ $item?->name ?? 'Nuevo usuario' }}</div>
                    <div class="break-all font-mono text-[13px] text-slate-600">{{ $item?->email ?? 'sin correo todavía' }}</div>
                </div>
                @if($item)
                    <div class="flex flex-wrap gap-2">
                        <span class="b {{ $item->is_active ? 'b-ok' : 'b-mute' }}">{{ $item->is_active ? 'Activo' : 'Inactivo' }}</span>
                        @foreach($item->roles as $r)<span class="b b-mute">{{ $r->name }}</span>@endforeach
                        @if($item->totp_enabled)<span class="b b-ok">2FA</span>@endif
                    </div>
                @endif
                <div class="perf" style="margin: 4px 0; width: 100%"></div>
                <p class="text-[13px] text-slate-600">Para dejar de dar acceso, desactiva la cuenta: no se eliminan, se conserva el historial y la auditoría.</p>
                <p class="text-[13px] text-slate-600">No puedes desactivarte ni quitarte el rol de super-admin a ti mismo.</p>
            </aside>

            <div class="flex min-w-0 flex-[1_1_600px] flex-col gap-5">
                <form method="POST" action="{{ $item ? route('admin.screens.update', ['admin-users', $item->getKey()]) : route('admin.screens.store', 'admin-users') }}" class="flex flex-col gap-5">
                    @csrf
                    @if($item)@method('PUT')@endif

                    <section class="snow-card flex flex-col gap-4 px-7 py-6">
                        <h2>Datos</h2>
                        <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4">
                            <div>
                                <label class="snow-label" for="f-name">Nombre</label>
                                <input id="f-name" type="text" name="name" value="{{ old('name', $item?->name) }}" class="snow-input" required maxlength="255">
                                @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="snow-label" for="f-email">Correo</label>
                                <input id="f-email" type="email" name="email" value="{{ old('email', $item?->email) }}" class="snow-input" required maxlength="255" autocomplete="off">
                                @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <label class="flex items-center gap-3 font-semibold">
                            <input type="hidden" name="is_active" value="0">
                            <input class="sw" type="checkbox" name="is_active" value="1" @checked($isActive)>Cuenta activa
                        </label>
                        @error('is_active')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </section>

                    <section class="snow-card flex flex-col gap-4 px-7 py-6">
                        <h2>Acceso</h2>
                        <div>
                            <label class="snow-label" for="f-password">Contraseña</label>
                            <input id="f-password" type="password" name="password" value="" placeholder="{{ $item ? 'Dejar vacío para mantener la actual' : 'Contraseña' }}" class="snow-input" autocomplete="new-password">
                            @if($item)<p class="mt-1.5 text-[13px] text-slate-500">Por seguridad, la contraseña actual no se muestra.</p>@endif
                            @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div class="flex flex-col items-start gap-2" x-data="{ shown: false }">
                            <button type="button" class="snow-btn-secondary"
                                x-on:click="
                                    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_+='];
                                    const pick = (chars) => chars[crypto.getRandomValues(new Uint32Array(1))[0] % chars.length];
                                    const all = sets.join('');
                                    let out = sets.map(pick);
                                    while (out.length < {{ $minLen }}) out.push(pick(all));
                                    out = out.sort(() => crypto.getRandomValues(new Uint32Array(1))[0] % 2 - 0.5).join('');
                                    const input = document.getElementById('f-password');
                                    input.value = out; input.type = 'text'; shown = true;
                                ">Generar contraseña</button>
                            <p x-show="shown" x-cloak class="text-xs font-medium text-amber-800">Cópiala y comunícasela al usuario: no se vuelve a mostrar.</p>
                            <p class="text-[13px] text-slate-500">Tú defines la contraseña y se la comunicas al usuario. Al guardarla se cierran sus otras sesiones abiertas.</p>
                        </div>
                    </section>

                    <section class="snow-card flex flex-col gap-4 px-7 py-6">
                        <div>
                            <h2>Rol</h2>
                            <p class="mt-1 text-sm text-slate-600">Cada usuario tiene un solo rol. Al elegir uno se reemplaza el anterior.</p>
                            @if(count($currentRoles) > 1)<p class="mt-2 rounded-[14px] bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-900">Esta cuenta tiene {{ count($currentRoles) }} roles ({{ implode(', ', $currentRoles) }}); al guardar se quedará solo con el que elijas.</p>@endif
                        </div>
                        <div role="radiogroup" aria-label="Rol" class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-3">
                            <label class="rcard"><input type="radio" name="role_names[]" value="" @checked($selectedRole === '')><span><span class="font-semibold">Sin rol</span><br><span class="text-[13px] text-slate-600">No ve ninguna pantalla</span></span></label>
                            @foreach(($roles ?? collect()) as $role)
                                <label class="rcard"><input type="radio" name="role_names[]" value="{{ $role->name }}" @checked($selectedRole === $role->name)><span><span class="font-mono font-medium">{{ $role->name }}</span>@if(in_array($role->name, $currentRoles, true))<br><span class="text-[13px] text-slate-600">Rol actual</span>@endif</span></label>
                            @endforeach
                        </div>
                        @error('role_names')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        @error('role_names.*')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </section>

                    <section class="snow-card flex flex-col gap-4 px-7 py-6" x-data="{ scope: '{{ $scope }}' }">
                        <h2>Alcance por cliente</h2>
                        <div role="radiogroup" aria-label="Alcance por cliente" class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-3">
                            <label class="rcard"><input type="radio" name="customer_scope" value="all" x-model="scope"><span class="font-semibold">Todos los clientes</span></label>
                            <label class="rcard"><input type="radio" name="customer_scope" value="selected" x-model="scope"><span class="font-semibold">Solo seleccionados</span></label>
                        </div>
                        <div x-show="scope === 'selected'" x-cloak>
                            <label class="snow-label" for="customer-ids">Clientes permitidos</label>
                            <select id="customer-ids" name="customer_ids[]" multiple class="snow-input min-h-[8rem]" :disabled="scope !== 'selected'">
                                @foreach(\App\Models\Customer::query()->orderBy('name')->get(['id', 'name', 'document']) as $customer)
                                    <option value="{{ $customer->id }}" @selected(in_array($customer->id, $scopeIds, true))>{{ $customer->name }}@if($customer->document) · {{ $customer->document }}@endif</option>
                                @endforeach
                            </select>
                            <p class="mt-1.5 text-[13px] text-slate-500">Cmd/Ctrl + clic para elegir varios.</p>
                            @error('customer_ids')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            @error('customer_ids.*')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <p class="text-[13px] text-slate-500">Con «solo seleccionados», este usuario únicamente ve y opera los cupones, activaciones y reportes de esos clientes. El super-admin nunca está restringido.</p>
                        @error('customer_scope')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </section>

                    <div class="flex flex-wrap gap-2.5">
                        <button type="submit" class="snow-btn-primary">Guardar</button>
                        <a href="{{ route('admin.screens.index', 'admin-users') }}" class="snow-btn-secondary">Cancelar</a>
                    </div>
                </form>

                @if($item)
                    <section class="snow-card flex flex-col gap-4 px-7 py-6">
                        <h2>Seguridad de la cuenta</h2>
                        @if($errors->has('security'))<p class="text-xs font-medium text-red-600">{{ $errors->first('security') }}</p>@endif
                        <div class="flex flex-wrap items-center gap-2.5">
                            <form method="POST" action="{{ route('admin.admin-users.revoke-sessions', $item) }}" onsubmit="return confirm('¿Cerrar {{ $isSelf ? 'tus otras sesiones' : 'todas las sesiones de este usuario' }}?');">
                                @csrf
                                <button type="submit" class="snow-btn-secondary">{{ $isSelf ? 'Cerrar mis otras sesiones' : 'Cerrar sus sesiones' }}</button>
                            </form>
                            @if($item->totp_enabled && ! $isSelf)
                                <form method="POST" action="{{ route('admin.admin-users.reset-2fa', $item) }}" onsubmit="return confirm('¿Reiniciar la verificación en dos pasos de este usuario? Podrá entrar solo con su contraseña hasta que la active de nuevo.');">
                                    @csrf
                                    <button type="submit" class="snow-btn-danger">Reiniciar verificación en 2 pasos</button>
                                </form>
                            @endif
                        </div>
                    </section>
                @endif
            </div>
        </section>
    </div>
</x-admin.layouts.app>
