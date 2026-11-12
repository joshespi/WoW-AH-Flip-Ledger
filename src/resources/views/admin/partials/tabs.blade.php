@php
    $tabs = [
        'admin.overview' => __('Overview'),
        'admin.users' => __('Users'),
        'admin.uploads' => __('Uploads'),
        'admin.fetch' => __('Price fetches'),
    ];
@endphp

<nav class="flex flex-wrap gap-2" aria-label="{{ __('Admin sections') }}">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" class="seg" @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
