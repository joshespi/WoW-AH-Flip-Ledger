@php
    $tabs = [
        'admin.overview' => __('Overview'),
        'admin.users' => __('Users'),
        'admin.uploads' => __('Uploads'),
        'admin.fetch' => __('Price fetches'),
    ];
@endphp

<nav class="flex flex-wrap gap-2 text-sm" aria-label="{{ __('Admin sections') }}">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" @class([
            'rounded-md px-3 py-1.5 border',
            'bg-indigo-600 text-white border-indigo-600' => request()->routeIs($route),
            'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => ! request()->routeIs($route),
        ]) @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
