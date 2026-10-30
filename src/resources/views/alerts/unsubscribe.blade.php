<x-guest-layout>
    <h1 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('Price alerts') }}</h1>

    @if ($done)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Price alert emails are off for this account. You can turn them back on in Settings.') }}</p>
        <x-secondary-button :href="route('settings.edit')" class="mt-4">{{ __('Open Settings') }}</x-secondary-button>
    @else
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Turn off all price alert emails for this account?') }}</p>
        <form method="post" action="{{ request()->fullUrl() }}" class="mt-4">
            <x-primary-button type="submit">{{ __('Turn alerts off') }}</x-primary-button>
        </form>
    @endif
</x-guest-layout>
