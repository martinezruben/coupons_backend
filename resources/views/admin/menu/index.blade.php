<x-admin.layouts.app title="Menú del panel">
    <div x-data="menuEditor(@js($groups), @js($icons))" class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <p class="max-w-[620px] text-[15px] text-slate-600">Administra los grupos (padres) y sus opciones (hijos) de la barra de navegación: cambia nombres e iconos, reordena, mueve opciones entre grupos, oculta lo que no uses y agrega enlaces propios. Cada persona seguirá viendo solo lo que sus permisos le permiten.</p>
            <div class="flex flex-wrap gap-2.5">
                <form method="POST" action="{{ route('admin.menu.reset') }}" onsubmit="return confirm('¿Restablecer el menú al original? Se pierden los cambios guardados.');">
                    @csrf
                    <button type="submit" class="snow-btn-secondary">Restablecer</button>
                </form>
                <button type="button" class="snow-btn-secondary" x-on:click="addGroup()">+ Nuevo grupo</button>
                <form method="POST" action="{{ route('admin.menu.update') }}" x-on:submit="saving = true">
                    @csrf @method('PUT')
                    <input type="hidden" name="payload" :value="JSON.stringify({ groups })">
                    <button type="submit" class="snow-btn-primary" :disabled="saving">Guardar menú</button>
                </form>
            </div>
        </div>
        @error('payload')<p class="rounded-[14px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $message }}</p>@enderror
        @if($errors->any() && ! $errors->has('payload'))<p class="rounded-[14px] bg-red-50 px-4 py-3 text-sm font-medium text-red-700">{{ $errors->first() }}</p>@endif

        <section class="flex flex-wrap items-start gap-6">
            <div class="flex min-w-0 flex-[1_1_640px] flex-col gap-4">
                <template x-for="(g, gi) in groups" :key="g.uid">
                    <article class="snow-card !overflow-visible" :class="g.visible ? '' : 'opacity-60'">
                        <header class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-5 py-3.5">
                            <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-xl border-[1.5px] border-slate-300 bg-white text-slate-700 hover:border-primary-600" x-on:click="open = !open" :aria-label="'Icono del grupo ' + g.label" x-html="icons[g.icon] || ''"></button>
                                <div x-show="open" x-cloak class="absolute left-0 top-full z-30 mt-1 grid w-[19rem] grid-cols-8 gap-1 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl">
                                    <template x-for="n in iconNames" :key="n"><button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg hover:bg-slate-100" :class="g.icon === n ? 'bg-green-50 text-green-700' : 'text-slate-600'" :title="n" x-html="icons[n]" x-on:click="g.icon = n; open = false"></button></template>
                                </div>
                            </div>
                            <div class="min-w-[180px] flex-1">
                                <label class="sr-only" :for="'g-' + g.uid">Nombre del grupo</label>
                                <input :id="'g-' + g.uid" type="text" class="snow-input !font-display !text-lg !font-semibold" x-model="g.label" maxlength="60" placeholder="Nombre del grupo">
                            </div>
                            <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" class="sw" x-model="g.visible">Visible</label>
                            <div class="flex items-center gap-1">
                                <button type="button" class="snow-icon-btn h-9 w-9" title="Subir grupo" x-on:click="moveGroup(gi, -1)" :disabled="gi === 0">↑</button>
                                <button type="button" class="snow-icon-btn h-9 w-9" title="Bajar grupo" x-on:click="moveGroup(gi, 1)" :disabled="gi === groups.length - 1">↓</button>
                                <button type="button" class="snow-icon-btn h-9 w-9 !text-red-700" title="Eliminar grupo (solo si no tiene pantallas)" x-on:click="removeGroup(gi)" :disabled="g.items.some(i => i.kind !== 'link')">✕</button>
                            </div>
                        </header>

                        <ul class="divide-y divide-slate-100">
                            <template x-for="(it, ii) in g.items" :key="it.uid">
                                <li class="px-5 py-3" :class="it.visible ? '' : 'opacity-60'">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                                            <button type="button" class="flex h-10 w-10 items-center justify-center rounded-xl border-[1.5px] border-slate-300 bg-white text-slate-500 hover:border-primary-600" x-on:click="open = !open" :aria-label="'Icono de ' + (it.label || it.defaultLabel)" x-html="icons[it.icon || it.defaultIcon] || ''"></button>
                                            <div x-show="open" x-cloak class="absolute left-0 top-full z-30 mt-1 grid w-[19rem] grid-cols-8 gap-1 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl">
                                                <template x-for="n in iconNames" :key="n"><button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg hover:bg-slate-100" :class="(it.icon || it.defaultIcon) === n ? 'bg-green-50 text-green-700' : 'text-slate-600'" :title="n" x-html="icons[n]" x-on:click="it.icon = n; open = false"></button></template>
                                                <button type="button" class="col-span-8 mt-1 rounded-lg px-2 py-1 text-xs text-primary-600 hover:bg-slate-100" x-show="it.kind !== 'link'" x-on:click="it.icon = null; open = false">Usar el icono original</button>
                                            </div>
                                        </div>
                                        <div class="min-w-[160px] flex-1">
                                            <label class="sr-only" :for="'i-' + it.uid">Nombre</label>
                                            <input :id="'i-' + it.uid" type="text" class="snow-input" x-model="it.label" maxlength="60" :placeholder="it.defaultLabel || 'Nombre del enlace'">
                                        </div>
                                        <span class="b b-mute !text-[11px]" x-text="it.kind === 'screen' ? 'Pantalla' : (it.kind === 'special' ? 'Acceso' : 'Enlace')"></span>
                                        <label class="flex items-center gap-2 text-sm font-medium" :title="it.locked ? 'El editor del menú no se puede ocultar' : ''"><input type="checkbox" class="sw" x-model="it.visible" :disabled="it.locked">Visible</label>
                                        <div class="flex items-center gap-1">
                                            <button type="button" class="snow-icon-btn h-9 w-9" title="Subir" x-on:click="moveItem(gi, ii, -1)" :disabled="ii === 0">↑</button>
                                            <button type="button" class="snow-icon-btn h-9 w-9" title="Bajar" x-on:click="moveItem(gi, ii, 1)" :disabled="ii === g.items.length - 1">↓</button>
                                            <select class="snow-input !min-h-[2.25rem] !w-40 !text-[13px]" aria-label="Mover a otro grupo" x-on:change="moveItemTo(gi, ii, $event.target.value); $event.target.value = ''">
                                                <option value="">Mover a…</option>
                                                <template x-for="(other, oi) in groups" :key="other.uid"><option :value="oi" x-show="oi !== gi" x-text="other.label || 'Grupo sin nombre'"></option></template>
                                            </select>
                                            <button type="button" class="snow-icon-btn h-9 w-9 !text-red-700" title="Quitar enlace" x-show="it.kind === 'link'" x-on:click="g.items.splice(ii, 1)">✕</button>
                                        </div>
                                    </div>
                                    <div x-show="it.kind === 'link'" class="mt-3 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-3 pl-[3.25rem]">
                                        <div><label class="snow-label" :for="'u-' + it.uid">Dirección</label><input :id="'u-' + it.uid" type="text" class="snow-input font-mono text-sm" x-model="it.url" placeholder="/admin/reports/redemptions o https://…" maxlength="500"></div>
                                        <div><label class="snow-label" :for="'p-' + it.uid">Permiso para verlo (opcional)</label><input :id="'p-' + it.uid" type="text" class="snow-input font-mono text-sm" x-model="it.permission" placeholder="customers.view" maxlength="100"></div>
                                        <label class="flex items-center gap-2 self-end pb-3 text-sm font-medium"><input type="checkbox" class="sw" x-model="it.new_tab">Abrir en otra pestaña</label>
                                    </div>
                                </li>
                            </template>
                            <li x-show="g.items.length === 0" class="px-5 py-4 text-sm text-slate-500">Este grupo está vacío.</li>
                        </ul>
                        <footer class="border-t border-slate-200 px-5 py-3"><button type="button" class="snow-btn-ghost snow-btn-sm" x-on:click="addLink(gi)">+ Agregar enlace</button></footer>
                    </article>
                </template>
            </div>

            <aside class="snow-card flex min-w-[260px] flex-[0_1_320px] flex-col gap-3 p-5 lg:sticky lg:top-24">
                <span class="eyebrow">Vista previa</span>
                <template x-for="g in groups.filter(x => x.visible)" :key="g.uid">
                    <div>
                        <div class="font-display text-lg font-semibold" x-text="g.label || 'Grupo sin nombre'"></div>
                        <ul class="mt-1 flex flex-col gap-0.5">
                            <template x-for="it in g.items.filter(x => x.visible)" :key="it.uid">
                                <li class="flex items-center gap-2.5 rounded-xl px-2 py-1.5 text-sm"><span class="nav-ico-box" x-html="icons[it.icon || it.defaultIcon] || ''"></span><span x-text="it.label || it.defaultLabel || '(sin nombre)'"></span></li>
                            </template>
                        </ul>
                    </div>
                </template>
                <div class="perf" style="margin: 4px 0"></div>
                <p class="text-xs text-slate-500">Los cambios se aplican al pulsar «Guardar menú». Las opciones ocultas siguen disponibles por su dirección y por el buscador según permisos; solo salen de la barra.</p>
            </aside>
        </section>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('menuEditor', (groups, icons) => {
                let n = 0;
                const uid = () => 'u' + (++n);
                groups.forEach(g => { g.uid = uid(); g.items.forEach(i => { i.uid = uid(); }); });
                return {
                    groups, icons, saving: false,
                    get iconNames() { return Object.keys(this.icons); },
                    moveGroup(i, d) { const j = i + d; if (j < 0 || j >= this.groups.length) return; [this.groups[i], this.groups[j]] = [this.groups[j], this.groups[i]]; },
                    moveItem(gi, i, d) { const items = this.groups[gi].items; const j = i + d; if (j < 0 || j >= items.length) return; [items[i], items[j]] = [items[j], items[i]]; },
                    moveItemTo(gi, i, target) { if (target === '') return; const [it] = this.groups[gi].items.splice(i, 1); this.groups[Number(target)].items.push(it); },
                    addGroup() { this.groups.push({ uid: uid(), key: '', label: 'Nuevo grupo', icon: 'squares-2x2', visible: true, items: [] }); },
                    removeGroup(i) { if (this.groups[i].items.every(x => x.kind === 'link') && confirm('¿Eliminar el grupo «' + this.groups[i].label + '»?')) this.groups.splice(i, 1); },
                    addLink(gi) { this.groups[gi].items.push({ uid: uid(), kind: 'link', ref: null, label: '', icon: 'link', url: '', permission: '', new_tab: false, visible: true, defaultLabel: '', defaultIcon: 'link', locked: false }); },
                };
            });
        });
    </script>
</x-admin.layouts.app>
