{{--
    Buscador del panel (⌘K / Ctrl+K): navega a las páginas que el usuario puede ver
    y ofrece buscar el texto dentro de los grids (?q=). Se abre con el evento
    `open-palette` o con el atajo de teclado.
--}}
@php
    $paletteEntries = \App\Support\AdminNavigation::searchEntries(auth('admin')->user());
@endphp
<div
    x-data="adminPalette(@js($paletteEntries))"
    x-on:open-palette.window="show()"
    x-on:keydown.window="onGlobalKey($event)"
>
    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[60] flex items-start justify-center bg-slate-900/40 px-4 pt-[12vh] backdrop-blur-sm"
        x-on:click.self="close()"
        role="dialog"
        aria-modal="true"
        aria-label="Buscar en el panel"
    >
        <div class="w-full max-w-lg overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl" x-on:keydown="onKey($event)">
            <div class="flex items-center gap-2 border-b border-slate-100 px-3">
                <svg class="h-4 w-4 shrink-0 text-slate-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input
                    x-ref="input"
                    x-model="query"
                    x-on:input="cursor = 0"
                    type="search"
                    placeholder="Ir a una página o buscar registros…"
                    class="h-11 w-full border-0 bg-transparent p-0 text-[13px] text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                    aria-label="Buscar"
                    autocomplete="off"
                >
                <kbd class="hidden shrink-0 rounded border border-slate-200 px-1.5 font-mono text-[10px] text-slate-500 sm:inline">Esc</kbd>
            </div>
            <ul class="max-h-[50vh] overflow-y-auto py-1" role="listbox">
                <template x-for="(r, i) in results" :key="r.url + r.label">
                    <li role="option" :aria-selected="i === cursor">
                        <a
                            :href="r.url"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-[12.5px]"
                            :class="i === cursor ? 'bg-primary-50 text-primary-800' : 'text-slate-700 hover:bg-slate-50'"
                            x-on:mouseenter="cursor = i"
                        >
                            <span class="min-w-0 truncate" x-text="r.label"></span>
                            <span class="shrink-0 text-[10.5px] text-slate-500" x-text="r.group"></span>
                        </a>
                    </li>
                </template>
                <li x-show="results.length === 0" class="px-3 py-6 text-center text-[12px] text-slate-500">No hay coincidencias.</li>
            </ul>
            <div class="flex items-center gap-3 border-t border-slate-100 bg-slate-50 px-3 py-1.5 text-[10.5px] text-slate-500">
                <span><kbd class="font-mono">↑ ↓</kbd> moverse</span>
                <span><kbd class="font-mono">Enter</kbd> abrir</span>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('alpine:init', () => {
                // Sin acentos ni mayúsculas para comparar ("parametros" encuentra "Parámetros")
                const norm = (s) => (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

                Alpine.data('adminPalette', (entries) => ({
                    open: false,
                    query: '',
                    cursor: 0,
                    get results() {
                        const q = norm(this.query);
                        if (!q) {
                            return entries.slice(0, 12);
                        }
                        const pages = entries.filter((e) => norm(e.label).includes(q) || norm(e.group).includes(q));
                        // Buscar el texto dentro de los grids que aceptan ?q=
                        const searches = q.length >= 2
                            ? entries.filter((e) => e.searchable).sort((a, b) => a.searchRank - b.searchRank).slice(0, 6).map((e) => ({
                                label: `Buscar «${this.query.trim()}» en ${e.label}`,
                                group: 'Registros',
                                url: e.url + (e.url.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(this.query.trim()),
                            }))
                            : [];
                        return [...pages, ...searches];
                    },
                    show() {
                        this.open = true;
                        this.query = '';
                        this.cursor = 0;
                        this.$nextTick(() => this.$refs.input.focus());
                    },
                    close() {
                        this.open = false;
                    },
                    onGlobalKey(e) {
                        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                            e.preventDefault();
                            this.open ? this.close() : this.show();
                        }
                    },
                    onKey(e) {
                        const n = this.results.length;
                        if (e.key === 'Escape') { this.close(); }
                        else if (e.key === 'ArrowDown' && n) { e.preventDefault(); this.cursor = (this.cursor + 1) % n; }
                        else if (e.key === 'ArrowUp' && n) { e.preventDefault(); this.cursor = (this.cursor - 1 + n) % n; }
                        else if (e.key === 'Enter' && n) { e.preventDefault(); window.location.href = this.results[this.cursor].url; }
                    },
                }));
            });
        </script>
    @endpush
@endonce
