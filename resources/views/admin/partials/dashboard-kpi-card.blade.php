{{-- KPI del dashboard; con `modal` la tarjeta es un botón que abre ese modal.
     tone: good | warn | bad | null (neutral). featured: tarjeta destacada con tinta de acento. --}}
@php
    $tone = $card['tone'] ?? null;
    $featured = ! empty($card['featured']);
    $modal = $card['modal'] ?? null;
    $tag = $modal ? 'button' : 'div';

    $border = match ($tone) {
        'warn' => 'border-amber-300 ring-1 ring-amber-200/70',
        'bad' => 'border-rose-300 ring-1 ring-rose-200/70',
        default => $featured ? 'border-primary-300 ring-1 ring-primary-100' : 'border-slate-200/90',
    };
    $value = match ($tone) {
        'warn' => 'text-amber-700',
        'bad' => 'text-rose-700',
        'good' => 'text-emerald-700',
        default => $featured ? 'text-primary-800' : 'text-slate-900',
    };
    $iconBox = match ($tone) {
        'warn' => 'border-amber-200 bg-amber-50 text-amber-600',
        'bad' => 'border-rose-200 bg-rose-50 text-rose-600',
        'good' => 'border-emerald-200 bg-emerald-50 text-emerald-600',
        default => $featured ? 'border-primary-200 bg-primary-50 text-primary-600' : 'border-slate-200 bg-slate-50 text-slate-500',
    };
@endphp
<{{ $tag }}
    @if ($modal) type="button" x-data x-on:click="$dispatch('open-modal', '{{ $modal }}')" aria-haspopup="dialog" @endif
    class="snow-card w-full p-3.5 text-left transition hover:shadow-[0_8px_30px_-8px_rgba(15,23,42,0.12)] {{ $border }}
        {{ $modal ? 'cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500' : '' }}"
>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="font-mono text-[11px] font-semibold uppercase leading-tight tracking-wider text-slate-500">{{ $card['label'] }}</p>
            <p class="mt-1.5 font-bold tabular-nums leading-tight tracking-tight {{ $featured ? 'text-2xl' : 'text-xl' }} {{ $value }}">{{ $card['value'] }}</p>
            @if (! empty($card['sub']))
                <p class="mt-0.5 text-[11px] leading-tight {{ $modal ? 'font-semibold text-primary-700 underline decoration-dotted underline-offset-2' : 'text-slate-500' }}">{{ $card['sub'] }}</p>
            @endif
        </div>
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border {{ $iconBox }}">
            <x-admin.snow.icon :name="$card['icon']" class="h-5 w-5" />
        </span>
    </div>
</{{ $tag }}>
