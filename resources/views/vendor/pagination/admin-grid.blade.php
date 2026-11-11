{{-- Paginación compacta estilo "Backend Admin" --}}
<nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="admin-grid-pagination">

    <div class="flex items-center justify-between gap-2 sm:hidden">
        @if ($paginator->onFirstPage())
            <span class="snow-icon-btn h-7 px-2 text-[10px] font-medium text-slate-300">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="snow-icon-btn h-7 px-2 text-[10px] font-medium">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="snow-icon-btn h-7 px-2 text-[10px] font-medium">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="snow-icon-btn h-7 px-2 text-[10px] font-medium text-slate-300">
                {!! __('pagination.next') !!}
            </span>
        @endif
    </div>

    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between sm:gap-2">
        <div>
            <p class="text-[10px] leading-tight text-slate-500">
                {!! __('Showing') !!}
                @if ($paginator->firstItem())
                    <span class="font-semibold text-slate-700">{{ $paginator->firstItem() }}</span>
                    {!! __('to') !!}
                    <span class="font-semibold text-slate-700">{{ $paginator->lastItem() }}</span>
                @else
                    {{ $paginator->count() }}
                @endif
                {!! __('of') !!}
                <span class="font-semibold text-slate-700">{{ $paginator->total() }}</span>
                {!! __('results') !!}
            </p>
        </div>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}" class="snow-icon-btn grid h-7 w-7 place-items-center text-slate-300" aria-hidden="true">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="snow-icon-btn grid h-7 w-7 place-items-center" aria-label="{{ __('pagination.previous') }}">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span aria-disabled="true">
                        <span class="grid h-7 w-7 cursor-default place-items-center text-[10px] font-medium text-slate-500">{{ $element }}</span>
                    </span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page">
                                <span class="grid h-7 min-w-7 cursor-default place-items-center rounded-md bg-primary-600 px-1.5 text-[10.5px] font-semibold text-white">{{ $page }}</span>
                            </span>
                        @else
                            <a href="{{ $url }}" class="snow-icon-btn grid h-7 min-w-7 place-items-center px-1.5 text-[10.5px] font-medium" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                {{ $page }}
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="snow-icon-btn grid h-7 w-7 place-items-center" aria-label="{{ __('pagination.next') }}">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                </a>
            @else
                <span aria-disabled="true" aria-label="{{ __('pagination.next') }}" class="snow-icon-btn grid h-7 w-7 place-items-center text-slate-300" aria-hidden="true">
                    <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                </span>
            @endif
        </div>
    </div>
</nav>
