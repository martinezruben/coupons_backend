@php
    // Mismas páginas y permisos que el buscador (App\Support\AdminNavigation)
    $navGroups = \App\Support\AdminNavigation::groups(auth('admin')->user());
    $navLinkClasses = fn (bool $active) => 'group flex items-center gap-2.5 rounded-md py-1.5 pl-2 pr-1.5 text-[13px] font-medium transition-all '
        . ($active ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700/60 hover:text-white');
    $navIconClasses = fn (bool $active) => 'h-4 w-4 shrink-0 ' . ($active ? 'text-primary-400' : 'text-slate-400 group-hover:text-slate-200');
@endphp

@if($navGroups !== [])
<div
    class="space-y-4"
    x-data='@json(['groups' => collect($navGroups)->mapWithKeys(fn ($g) => [$g['key'] => true])->all()])'
>
    @foreach($navGroups as $group)
        <div>
            <button
                type="button"
                class="flex w-full items-center justify-between gap-1 rounded-md px-2 pb-1.5 pt-0.5 text-left font-mono text-[11px] font-semibold uppercase tracking-wider text-slate-400 hover:text-slate-200"
                :aria-expanded="groups['{{ $group['key'] }}'] ? 'true' : 'false'"
                @click="groups['{{ $group['key'] }}'] = !groups['{{ $group['key'] }}']"
            >
                <span class="flex items-center gap-1.5">
                    <x-admin.snow.icon :name="$group['icon']" class="h-3.5 w-3.5 shrink-0" />
                    {{ $group['label'] }}
                </span>
                <svg class="h-3 w-3 shrink-0 opacity-60 transition" :class="groups['{{ $group['key'] }}'] ? '' : '-rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
            </button>
            <div x-show="groups['{{ $group['key'] }}']" class="mt-0.5 space-y-0.5 pl-0">
                @foreach($group['items'] as $item)
                    <a
                        href="{{ $item['url'] }}"
                        class="{{ $navLinkClasses($item['active']) }}"
                        @if($item['active']) aria-current="page" @endif
                    >
                        <x-admin.snow.icon :name="$item['icon']" class="{{ $navIconClasses($item['active']) }}" />
                        <span class="min-w-0 flex-1 truncate leading-tight">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endif
