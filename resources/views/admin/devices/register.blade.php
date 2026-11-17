<x-admin.layouts.app title="Registrar dispositivo">
    @php $me = auth('admin')->id(); @endphp
    <form method="POST" action="{{ route('admin.devices.register') }}" class="grid grid-cols-1 gap-5 lg:grid-cols-3" autocomplete="off">
        @csrf
        <div class="flex flex-col gap-5 lg:col-span-2">
            <section class="snow-card flex flex-col gap-4 px-7 py-6">
                <div>
                    <h2>1. Dispositivo y localidad</h2>
                    <p class="mt-1 text-sm text-slate-600">Cada terminal queda ligado a una localidad. El identificador aparece en el terminal al abrir la app por primera vez.</p>
                </div>
                <div class="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-4">
                    <div>
                        <label class="snow-label" for="device_fingerprint">Identificador del dispositivo <span class="text-red-600">*</span></label>
                        <input id="device_fingerprint" name="device_fingerprint" type="text" value="{{ old('device_fingerprint') }}" class="snow-input font-mono" maxlength="255" required autofocus>
                        @error('device_fingerprint')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="snow-label" for="name">Nombre (opcional)</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" class="snow-input" maxlength="100" placeholder="Caja 1">
                        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div>
                    <label class="snow-label" for="location_id">Localidad a la que se asigna <span class="text-red-600">*</span></label>
                    <select id="location_id" name="location_id" class="snow-input" required>
                        <option value="">— Elegir localidad —</option>
                        @foreach($locations as $l)<option value="{{ $l->id }}" @selected(old('location_id') === $l->id)>{{ $l->name }}</option>@endforeach
                    </select>
                    @error('location_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="snow-card flex flex-col gap-4 px-7 py-6">
                <div>
                    <h2>2. Aprobación de {{ $required }} administradores</h2>
                    <p class="mt-1 text-sm text-slate-600">Ninguna persona registra un dispositivo sola: elige a {{ $required }} administradores con permiso de registro e introduce el código actual de su app autenticadora (6 dígitos).</p>
                </div>
                @if($approvers->count() < $required)
                    <p class="rounded-[14px] bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">Hay {{ $approvers->count() }} administrador(es) que pueden aprobar (con permiso de registro y la verificación en dos pasos activa). Se necesitan {{ $required }}.</p>
                @endif
                @error('approvals')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="grid grid-cols-[repeat(auto-fit,minmax(280px,1fr))] gap-4">
                    @for($i = 0; $i < $required; $i++)
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <span class="eyebrow">Aprobación {{ $i + 1 }}</span>
                            <div class="mt-3">
                                <label class="snow-label" for="ap-{{ $i }}-user">Administrador</label>
                                <select id="ap-{{ $i }}-user" name="approvals[{{ $i }}][admin_user_id]" class="snow-input" required>
                                    <option value="">— Elegir —</option>
                                    @foreach($approvers as $a)<option value="{{ $a->id }}" @selected((string) old("approvals.$i.admin_user_id") === (string) $a->id)>{{ $a->name }}@if($a->id === $me) (tú)@endif</option>@endforeach
                                </select>
                                @error("approvals.$i.admin_user_id")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="mt-3">
                                <label class="snow-label" for="ap-{{ $i }}-code">Código del autenticador</label>
                                <input id="ap-{{ $i }}-code" name="approvals[{{ $i }}][code]" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="123456" class="snow-input text-center font-mono tracking-[0.3em]" autocomplete="one-time-code" required>
                                @error("approvals.$i.code")<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    @endfor
                </div>
            </section>
        </div>

        <aside class="lg:col-span-1">
            <div class="snow-card flex flex-col gap-5 p-6 lg:sticky lg:top-24">
                <span class="eyebrow">Cómo funciona</span>
                <ol class="flex flex-col gap-4 text-sm">
                    <li class="flex items-start gap-3"><span class="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-primary-600 font-mono text-sm font-bold text-lime-300">1</span><span><strong>Registro aprobado</strong><br><span class="text-slate-600">Dos administradores confirman con su autenticador.</span></span></li>
                    <li class="flex items-start gap-3"><span class="flex h-8 w-8 flex-none items-center justify-center rounded-full border-2 border-slate-300 font-mono text-sm font-bold text-slate-600">2</span><span><strong>Vinculación</strong><br><span class="text-slate-600">En el terminal, introduce el código de la localidad (Localidades → Clave de acceso).</span></span></li>
                </ol>
                <div class="perf" style="margin: 0"></div>
                <p class="text-sm text-slate-600">Un terminal que no esté registrado aquí no puede darse de alta por su cuenta. Quedan en la auditoría quién registró el dispositivo y quién lo aprobó.</p>
                <div class="flex flex-wrap gap-2.5">
                    <button type="submit" class="snow-btn-primary">Registrar dispositivo</button>
                    <a href="{{ route('admin.screens.index', 'devices') }}" class="snow-btn-secondary">Cancelar</a>
                </div>
            </div>
        </aside>
    </form>
</x-admin.layouts.app>
