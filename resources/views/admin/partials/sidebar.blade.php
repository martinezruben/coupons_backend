{{--
    Navegación lateral (vertical navbar de Tabler/UI): marca, Inicio y los grupos del menú
    (config/admin_nav_groups.php) como secciones plegables. Solo muestra lo que el usuario puede ver.
--}}
@php
    $admin = auth('admin')->user();
    $groups = array_values(array_filter(\App\Support\AdminNavigation::groups($admin), fn ($g) => $g['items'] !== []));
    $dashActive = request()->routeIs('admin.dashboard');
    $appName = config('app.name', 'Coupify');
@endphp
<div class="flex h-full min-h-0 flex-col">
    <a href="{{ route('admin.dashboard') }}" class="sidenav-brand" aria-label="{{ $appName }}: inicio">
        <span class="brand-mark">{{ strtoupper(mb_substr($appName, 0, 1)) }}</span>
        <span>{{ $appName }}</span>
    </a>

    <nav class="min-h-0 flex-1 overflow-y-auto p-3" aria-label="Navegación principal"
         x-data="{
            closed: (() => { try { return JSON.parse(localStorage.getItem('sidenav-closed') || '[]'); } catch (e) { return []; } })(),
            toggle(key) {
                this.closed = this.closed.includes(key) ? this.closed.filter(k => k !== key) : [...this.closed, key];
                try { localStorage.setItem('sidenav-closed', JSON.stringify(this.closed)); } catch (e) {}
            },
         }">
        <a href="{{ route('admin.dashboard') }}" class="sidenav-link {{ $dashActive ? 'is-active' : '' }}" @if($dashActive) aria-current="page" @endif>
            <x-admin.snow.icon name="home" class="h-5 w-5 shrink-0" />
            Inicio
        </a>

        @foreach($groups as $group)
            @php $groupActive = collect($group['items'])->contains(fn ($i) => $i['active']); @endphp
            <div class="mt-4">
                <button type="button" class="sidenav-group-btn"
                        :aria-expanded="(!closed.includes('{{ $group['key'] }}') || {{ $groupActive ? 'true' : 'false' }}) ? 'true' : 'false'"
                        x-on:click="toggle('{{ $group['key'] }}')">
                    <span>{{ $group['label'] }}</span>
                    <svg class="h-3 w-3 shrink-0 transition" :class="(closed.includes('{{ $group['key'] }}') && !{{ $groupActive ? 'true' : 'false' }}) ? '-rotate-90' : ''" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                </button>
                <div class="mt-1 space-y-0.5" x-show="!closed.includes('{{ $group['key'] }}') || {{ $groupActive ? 'true' : 'false' }}">
                    @foreach($group['items'] as $item)
                        <a href="{{ $item['url'] }}" class="sidenav-link {{ $item['active'] ? 'is-active' : '' }}" @if($item['active']) aria-current="page" @endif>
                            <x-admin.snow.icon :name="$item['icon']" class="h-5 w-5 shrink-0 {{ $item['active'] ? '' : 'text-slate-500' }}" />
                            <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>
</div>
