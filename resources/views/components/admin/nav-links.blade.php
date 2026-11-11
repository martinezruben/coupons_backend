@props([
    'variant' => 'sidebar',
])

@php
    /** @var \App\Models\AdminUser|null $admin */
    $admin = auth('admin')->user();
    $currentScreen = request()->route('screen');
@endphp

@if($admin)
    @foreach(config('admin_screens') as $key => $screen)
        @continue($key === 'dashboard')
        @continue(! empty($screen['exclude_from_nav']))
        @if(\App\Support\AdminRbac::canAccessScreen($admin, $key))
            @php
                $active = $currentScreen === $key;
                $base = 'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors';
                $activeClasses = 'bg-primary-50 text-primary-700 shadow-snow';
                $inactiveClasses = 'text-snow-600 hover:bg-snow-100 hover:text-snow-900';
            @endphp
            <a
                class="{{ $base }} {{ $active ? $activeClasses : $inactiveClasses }}"
                href="{{ route('admin.screens.index', $key) }}"
                @if($active) aria-current="page" @endif
            >
                <x-admin.snow.icon :name="$screen['icon'] ?? 'squares-2x2'" class="w-5 h-5 {{ $active ? 'text-primary-600' : 'text-snow-400 group-hover:text-snow-600' }}" />
                <span>{{ $screen['label'] }}</span>
            </a>
        @endif
    @endforeach
@endif
