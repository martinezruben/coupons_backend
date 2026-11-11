@php
    $dashActive = request()->routeIs('admin.dashboard', 'admin.dashboard.*');
@endphp
<div class="flex h-full min-h-0 flex-col text-slate-300">
    <div class="flex shrink-0 items-center gap-2.5 border-b border-slate-700 px-3 py-3">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-600 text-[13px] font-bold text-white">
            {{ strtoupper(substr(config('app.name', 'Tap&Go'), 0, 1)) }}
        </span>
        <div class="min-w-0 flex-1 leading-tight">
            <p class="truncate text-[12.5px] font-semibold text-white">{{ auth('admin')->user()->name }}</p>
            <p class="truncate font-mono text-[11px] text-slate-400">{{ auth('admin')->user()->email }}</p>
        </div>
    </div>

    <div class="px-3 pb-0 pt-2.5">
        <a
            href="{{ route('admin.dashboard') }}"
            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-[13px] font-medium transition-all
                {{ $dashActive
                    ? 'bg-slate-700 text-white'
                    : 'text-slate-300 hover:bg-slate-700/60 hover:text-white' }}"
            @if($dashActive) aria-current="page" @endif
        >
            <x-admin.snow.icon name="home" class="h-4 w-4 shrink-0 {{ $dashActive ? 'text-primary-400' : 'text-slate-400' }}" />
            <span class="truncate">Dashboard</span>
        </a>
    </div>

    <nav class="min-h-0 flex-1 overflow-y-auto px-3 pb-3 pt-3.5">
        <x-admin.nav-groups />
    </nav>

    <div class="mt-auto shrink-0 border-t border-slate-700 px-3 py-2.5">
        <div class="flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-wider text-slate-400">
            <span class="flex h-5 w-5 items-center justify-center rounded bg-primary-600 text-[9px] font-bold text-white">P</span>
            Tap&Go
        </div>
    </div>
</div>
