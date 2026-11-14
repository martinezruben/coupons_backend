<x-admin.layouts.app :title="$cfg['label']">
    <div class="snow-card">
        <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50/80 to-white px-4 py-2.5">
            <h2 class="text-sm font-bold text-slate-900">{{ $item ? 'Editar' : 'Crear' }} · {{ $cfg['label'] }}</h2>
            <p class="mt-0.5 text-xs text-slate-500">
                @if(($screen ?? null) === 'devices' && $item)
                    Puedes editar el nombre, restablecer la última sincronización y bloquear o reactivar el acceso (con motivo).
                @else
                    Completa los campos y guarda.
                @endif
            </p>
        </div>
        @if(($screen ?? null) === 'devices' && $item)
            <div class="grid grid-cols-1 gap-3 px-4 py-4 md:grid-cols-2 md:gap-x-4 md:gap-y-3">
                <div class="md:col-span-2">
                    <span class="snow-label">Localidad</span>
                    <p class="mt-0.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-sm text-slate-800">{{ $item->location?->name ?? '—' }}</p>
                </div>
                <div class="md:col-span-2">
                    <span class="snow-label">Huella del dispositivo</span>
                    <p class="mt-0.5 break-all rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 font-mono text-sm text-slate-800">{{ $item->device_fingerprint }}</p>
                </div>
                <div class="md:col-span-2">
                    <span class="snow-label">Versión de la app</span>
                    <p class="mt-0.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 font-mono text-sm text-slate-800">{{ $item->app_version ?? 'Sin reportar' }}@if ($item->app_version_seen_at) <span class="font-sans text-xs text-slate-500">· vista {{ $item->app_version_seen_at->format('Y-m-d H:i') }}</span>@endif</p>
                </div>
                <div class="md:col-span-2">
                    <span class="snow-label">Última sincronización</span>
                    <p class="mt-0.5 rounded-md border border-slate-200 bg-slate-50 px-2 py-1.5 text-sm text-slate-800 tabular-nums">{{ $item->last_sync_at?->format('Y-m-d H:i') ?? '—' }}</p>
                </div>
                <div class="md:col-span-2">
                    <form method="POST" action="{{ route('admin.screens.update', [$screen, $item->getKey()]) }}" class="flex flex-col gap-3 border-t border-slate-100 pt-3">
                        @csrf
                        @method('PUT')
                        <div>
                            <label class="snow-label" for="f-name">Nombre</label>
                            <input id="f-name" type="text" name="name" value="{{ old('name', $item->name) }}" maxlength="100" placeholder="Nombre del dispositivo" class="snow-input max-w-md">
                            @error('name')
                                <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <button type="submit" class="snow-btn-primary">Guardar</button>
                            <a href="{{ route('admin.screens.index', $screen) }}" class="snow-btn-secondary">Cancelar</a>
                        </div>
                    </form>
                </div>
                <div class="md:col-span-2 border-t border-slate-100 pt-3" id="acceso">
                    <span class="snow-label">Acceso del dispositivo</span>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <x-admin.snow.badge field="is_enabled" :value="$item->is_enabled" />
                        @if (! $item->is_enabled && $item->disabled_at)
                            <span class="text-xs text-slate-600">Bloqueado el {{ $item->disabled_at->format('Y-m-d H:i') }}@if ($item->disabled_by) por {{ $item->disabled_by }}@endif</span>
                        @endif
                    </div>
                    @if (! $item->is_enabled && $item->disabled_reason)
                        <p class="mt-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-xs text-amber-900"><span class="font-semibold">Motivo:</span> {{ $item->disabled_reason }}</p>
                    @endif
                    @error('is_enabled')
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror

                    <form
                        method="POST"
                        action="{{ $item->is_enabled ? route('admin.devices.block', $item) : route('admin.devices.unblock', $item) }}"
                        class="mt-2 flex max-w-xl flex-col gap-2"
                        onsubmit="return confirm('{{ $item->is_enabled ? '¿Bloquear este dispositivo y cerrar su sesión?' : '¿Reactivar este dispositivo?' }}');"
                    >
                        @csrf
                        <label class="snow-label" for="f-reason">{{ $item->is_enabled ? 'Motivo del bloqueo' : 'Motivo de la reactivación' }}</label>
                        <textarea id="f-reason" name="reason" rows="2" maxlength="255" required class="snow-input" placeholder="{{ $item->is_enabled ? 'Ej.: tablet extraviada en sucursal Centro' : 'Ej.: equipo recuperado y verificado' }}">{{ old('reason') }}</textarea>
                        @error('reason')
                            <p class="text-xs font-medium text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-slate-500">
                            @if ($item->is_enabled)
                                Se cerrará su sesión en el servidor. El equipo lo notará en su próxima conexión; mientras esté sin internet puede seguir vendiendo con lo que ya tiene guardado.
                            @else
                                El equipo deberá iniciar sesión de nuevo desde la app para volver a sincronizar.
                            @endif
                        </p>
                        <div>
                            <button type="submit" class="{{ $item->is_enabled ? 'snow-btn-danger' : 'snow-btn-primary' }}">
                                {{ $item->is_enabled ? 'Bloquear y cerrar sesión' : 'Reactivar dispositivo' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @else
        <form method="POST" action="{{ $item ? route('admin.screens.update', [$screen, $item->getKey()]) : route('admin.screens.store', $screen) }}" class="grid grid-cols-1 gap-3 px-4 py-4 md:grid-cols-2 md:gap-x-4 md:gap-y-3" x-data="selectCascade">
            @csrf
            @if($item)
                @method('PUT')
            @endif

            @foreach($cfg['fields'] as $field)
                @continue(!empty($cfg['foreign_labels'][$field]['virtual']))
                @if($field === 'id' && ! $item)
                    @continue
                @endif
                <div class="{{ in_array($field, ['last_error_message', 'error_message'], true) ? 'md:col-span-2' : '' }}">
                    <label class="snow-label" for="f-{{ $field }}">
                        {{ \App\Support\AdminGridCell::headerLabel($field, $cfg) }}
                    </label>
                    @if(str_starts_with($field, 'is_'))
                        @php
                            // Un registro nuevo nace activo (como el valor por defecto de la base de datos); el resto de banderas, en No
                            $boolDefault = $item ? $item->{$field} : ($field === 'is_active' ? 1 : 0);
                        @endphp
                        <select id="f-{{ $field }}" name="{{ $field }}" class="snow-input">
                            <option value="1" @selected(old($field, $boolDefault) == 1)>Sí</option>
                            <option value="0" @selected(old($field, $boolDefault) == 0)>No</option>
                        </select>
                    @elseif(!empty($cfg['foreign_labels'][$field]))
                        @php
                            $fkOpts = ($foreignSelectOptions ?? [])[$field] ?? collect();
                        @endphp
                        <select id="f-{{ $field }}" name="{{ $field }}" class="snow-input" @if(!empty($cfg['foreign_labels'][$field]['depends'])) data-depends="{{ $cfg['foreign_labels'][$field]['depends'] }}" @endif>
                            <option value="">{{ __('— Seleccionar —') }}</option>
                            @foreach($fkOpts as $opt)
                                <option value="{{ $opt['id'] }}" @selected((string) old($field, $item?->{$field}) === (string) $opt['id']) @if(isset($opt['parent'])) data-parent="{{ $opt['parent'] }}" @endif>{{ $opt['label'] }}</option>
                            @endforeach
                        </select>
                    @elseif($field === 'id' && $item)
                        <input id="f-id" type="text" value="{{ $item->getKey() }}" class="snow-input bg-slate-50 text-slate-700" readonly aria-readonly="true">
                    @elseif($field === 'password')
                        <input id="f-{{ $field }}" type="password" name="{{ $field }}" value="" placeholder="{{ $item ? 'Dejar vacío para mantener la actual' : 'Contraseña' }}" class="snow-input" autocomplete="new-password">
                        @if($item)
                            <p class="mt-1 text-xs text-slate-500">Por seguridad, la contraseña actual no se muestra.</p>
                        @endif
                        @if($screen === 'admin-users')
                            @php
                                $minLen = max(14, (int) (\App\Models\SystemParameter::query()->value('admin_password_min_length') ?? 8));
                            @endphp
                            <div class="mt-1.5 flex flex-wrap items-center gap-2" x-data="{ shown: false }">
                                <button
                                    type="button"
                                    class="snow-btn-secondary"
                                    x-on:click="
                                        const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_+='];
                                        const pick = (chars) => chars[crypto.getRandomValues(new Uint32Array(1))[0] % chars.length];
                                        const all = sets.join('');
                                        let out = sets.map(pick);
                                        while (out.length < {{ $minLen }}) out.push(pick(all));
                                        out = out.sort(() => crypto.getRandomValues(new Uint32Array(1))[0] % 2 - 0.5).join('');
                                        const input = document.getElementById('f-password');
                                        input.value = out; input.type = 'text'; shown = true;
                                    "
                                >Generar contraseña</button>
                                <span x-show="shown" x-cloak class="text-xs font-medium text-amber-800">Cópiala y comunícasela al usuario: no se vuelve a mostrar.</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Tú defines la contraseña y se la comunicas al usuario. Al guardarla se cierran sus otras sesiones abiertas.</p>
                        @endif
                    @elseif($field === 'pin4')
                        <div class="mt-0.5 flex flex-col gap-3" x-data="{
                            digits: ['', '', '', ''],
                            sync(value) {
                                const clean = value.replace(/\D/g, '').slice(0, 4).split('');
                                this.digits = [clean[0] || '', clean[1] || '', clean[2] || '', clean[3] || ''];
                            },
                        }">
                            @if($item && method_exists($item, 'pin4Plain') && $item->pin4Plain() !== null)
                                <div>
                                    <span class="mb-1 block text-2xs font-bold uppercase tracking-[0.04em] text-slate-500">PIN actual</span>
                                    <div class="inline-flex gap-1.5" aria-hidden="true">
                                        @foreach(str_split(str_pad((string) $item->pin4Plain(), 4)) as $digit)
                                            <span class="flex h-9 w-8 items-center justify-center rounded-md border border-slate-200 bg-slate-50 font-mono text-base font-bold tabular-nums text-slate-700 shadow-sm">{{ trim($digit) }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <div>
                                <span class="mb-1 block text-2xs font-bold uppercase tracking-[0.04em] text-slate-500">{{ $item ? 'Nuevo PIN' : 'PIN' }}</span>
                                <div class="relative inline-flex cursor-text gap-1.5" @click="$refs.pin4Input.focus()">
                                    <template x-for="(digit, index) in digits" :key="index">
                                        <span
                                            class="flex h-10 w-9 items-center justify-center rounded-md border-2 font-mono text-lg font-bold tabular-nums text-slate-900 shadow-sm transition"
                                            :class="digit ? 'border-primary-400 bg-primary-50/70' : 'border-slate-200 bg-white'"
                                            x-text="digit || '·'"
                                        ></span>
                                    </template>
                                    <input
                                        x-ref="pin4Input"
                                        id="f-{{ $field }}"
                                        type="text"
                                        name="{{ $field }}"
                                        inputmode="numeric"
                                        maxlength="4"
                                        autocomplete="off"
                                        aria-label="{{ $item ? 'Nuevo PIN' : 'PIN' }}"
                                        class="absolute inset-0 h-full w-full cursor-text opacity-0"
                                        @input="sync($event.target.value)"
                                    >
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $item ? 'Dejar vacío para mantener el PIN actual.' : '4 dígitos, ej. 7391' }}</p>
                            </div>
                        </div>
                    @elseif(!empty($cfg['select_options'][$field] ?? []))
                        @php
                            $selectDefault = $item?->{$field} ?? array_key_first($cfg['select_options'][$field] ?? []);
                        @endphp
                        <select id="f-{{ $field }}" name="{{ $field }}" class="snow-input" required>
                            @foreach($cfg['select_options'][$field] as $optionValue => $optionLabel)
                                <option value="{{ $optionValue }}" @selected((string) old($field, $selectDefault) === (string) $optionValue)>{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                    @elseif(str_ends_with($field, '_at') || in_array($field, ['valid_from', 'valid_to', 'start_time', 'end_time', 'occurred_at', 'synced_at'], true))
                        @php
                            $dtVal = old($field);
                            if ($dtVal === null || $dtVal === '') {
                                if ($item?->{$field}) {
                                    $dtVal = $item->{$field}->format('Y-m-d\TH:i');
                                } elseif (($screen ?? null) === 'licenses' && $item === null && in_array($field, ['valid_from', 'valid_to'], true)) {
                                    $dtVal = $field === 'valid_from'
                                        ? now()->subDay()->format('Y-m-d\TH:i')
                                        : now()->addYear()->format('Y-m-d\TH:i');
                                } else {
                                    $dtVal = '';
                                }
                            }
                        @endphp
                        <input id="f-{{ $field }}" type="datetime-local" name="{{ $field }}" value="{{ $dtVal }}" class="snow-input">
                    @else
                        <input id="f-{{ $field }}" type="text" name="{{ $field }}" value="{{ old($field, $item?->{$field}) }}" class="snow-input">
                    @endif
                    @error($field)
                        <p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach

            @if($screen === 'admin-users')
                <div class="md:col-span-2">
                    <label class="snow-label" for="roles-ms">Roles</label>
                    <select id="roles-ms" name="role_names[]" multiple class="snow-input min-h-[7.5rem]">
                        @php
                            $currentRoles = $item ? $item->roles->pluck('name')->all() : [];
                        @endphp
                        @foreach(($roles ?? collect()) as $role)
                            <option value="{{ $role->name }}" @selected(in_array($role->name, old('role_names', $currentRoles), true))>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Cmd/Ctrl + clic para varios roles.</p>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-2 md:col-span-2 md:pt-1">
                <button type="submit" class="snow-btn-primary">Guardar</button>
                <a href="{{ route('admin.screens.index', $screen) }}" class="snow-btn-secondary">Cancelar</a>
            </div>
        </form>

        @if($screen === 'admin-users' && $item)
            @php
                $isSelf = auth('admin')->id() === $item->getKey();
            @endphp
            <div class="mx-4 mb-4 border-t border-slate-100 pt-3">
                <h2 class="snow-label">Seguridad de la cuenta</h2>
                @if($errors->has('security'))
                    <p class="mb-2 text-xs font-medium text-red-600">{{ $errors->first('security') }}</p>
                @endif
                <div class="flex flex-wrap items-center gap-2">
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
                <p class="mt-1.5 text-xs text-slate-500">
                    Para dejar de dar acceso, desactiva la cuenta (no se eliminan: se conserva el historial y la auditoría).
                    @if($isSelf) No puedes desactivarte ni quitarte el rol de super-admin a ti mismo. @endif
                </p>
            </div>
        @endif
        @endif
    </div>
</x-admin.layouts.app>
