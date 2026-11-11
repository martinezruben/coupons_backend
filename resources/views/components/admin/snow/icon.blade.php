@props([
    'name' => 'squares-2x2',
    'class' => 'w-5 h-5 shrink-0',
])

@php
    // Legacy names kept for backwards compatibility with existing callers
    // that don't map 1:1 to a Heroicons outline slug.
    $aliases = [
        'identifier' => 'hashtag',
    ];

    $slug = $aliases[$name] ?? $name;
@endphp

{{ svg('heroicon-o-'.$slug, $class, array_merge(['aria-hidden' => 'true'], $attributes->getAttributes())) }}
