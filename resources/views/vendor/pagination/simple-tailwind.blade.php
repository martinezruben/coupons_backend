@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between gap-3">

        @if ($paginator->onFirstPage())
            <span class="inline-flex items-center rounded-lg border border-snow-200 bg-white px-4 py-2 text-sm font-medium leading-5 text-snow-400 cursor-not-allowed shadow-snow">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex items-center rounded-lg border border-snow-200 bg-white px-4 py-2 text-sm font-medium leading-5 text-snow-700 shadow-snow transition hover:bg-snow-50 focus:outline-none focus:ring-2 focus:ring-primary-500/30">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex items-center rounded-lg border border-snow-200 bg-white px-4 py-2 text-sm font-medium leading-5 text-snow-700 shadow-snow transition hover:bg-snow-50 focus:outline-none focus:ring-2 focus:ring-primary-500/30">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="inline-flex items-center rounded-lg border border-snow-200 bg-white px-4 py-2 text-sm font-medium leading-5 text-snow-400 cursor-not-allowed shadow-snow">
                {!! __('pagination.next') !!}
            </span>
        @endif

    </nav>
@endif
