{{-- About / Privacy / Terms, for the footer of pages outside the app. --}}
<nav {{ $attributes->class(['flex gap-4 text-xs text-gray-500 dark:text-gray-400']) }} aria-label="{{ __('About this site') }}">
    <a class="underline" href="{{ route('about') }}">{{ __('About') }}</a>
    <a class="underline" href="{{ route('stats') }}">{{ __('Stats') }}</a>
    <a class="underline" href="{{ route('scoreboard') }}">{{ __('Scoreboard') }}</a>
    <a class="underline" href="{{ route('privacy') }}">{{ __('Privacy') }}</a>
    <a class="underline" href="{{ route('terms') }}">{{ __('Terms') }}</a>
</nav>
