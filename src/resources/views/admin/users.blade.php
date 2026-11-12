<x-app-layout :title="__('Admin: users')" games="any">
    @include('admin.partials.tabs')

    <div class="card p-4 sm:p-6">
        <form method="get" class="flex flex-wrap items-center gap-3">
            <input type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="{{ __('Search name or email') }}" aria-label="{{ __('Search name or email') }}"
                   class="w-full sm:w-72 field text-sm">
            <x-secondary-button type="submit">{{ __('Search') }}</x-secondary-button>
            @if ($search !== '')<a class="link text-sm" href="{{ route('admin.users') }}">{{ __('Clear') }}</a>@endif
        </form>

        <x-input-error class="mt-3" :messages="$errors->get('impersonate')" />

        @php($me = auth()->user())
        @if ($users->isEmpty())
            <p class="empty">{{ __('No users match.') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="data-table">
                    <caption class="sr-only">{{ __('Accounts, newest first.') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('User') }}</th>
                            <th scope="col">{{ __('Joined') }}</th>
                            <th scope="col">{{ __('Verified') }}</th>
                            <th scope="col" class="num">{{ __('Uploads') }}</th>
                            <th scope="col">{{ __('Last upload') }}</th>
                            <th scope="col" class="num">{{ __('Buys') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $u)
                            <tr>
                                <td>
                                    {{ $u->name }} <span class="text-gray-500 dark:text-gray-400">{{ $u->email }}</span>
                                    @if ($u->is_admin)<x-chip type="Admin" tone="indigo" class="ms-1" />@endif
                                    @if ($u->is_demo)<x-chip type="Demo" class="ms-1" />@endif
                                </td>
                                <td><x-time :at="$u->created_at" format="Y-m-d" /></td>
                                <td>{{ $u->email_verified_at ? __('verified') : __('unverified') }}</td>
                                <td class="num">{{ $u->uploads_ok }}@if ($u->uploads_failed)<span class="text-red-600 dark:text-red-400"> / {{ $u->uploads_failed }} {{ __('failed') }}</span>@endif</td>
                                <td><x-time :at="$u->last_upload" /></td>
                                <td class="num">{{ number_format($u->trades) }}</td>
                                <td class="text-end whitespace-nowrap">
                                    @unless ($u->is_admin || $u->is_demo || $u->id === $me->id || ! $u->email_verified_at)
                                        <form method="post" action="{{ route('admin.impersonate', $u->id) }}" x-data @submit="confirm(@js(__('View the site as this user? It is read-only and is logged.'))) || $event.preventDefault()">
                                            @csrf
                                            <button class="link text-sm">{{ __('View as') }}</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $users->links() }}</div>
        @endif
    </div>
</x-app-layout>
