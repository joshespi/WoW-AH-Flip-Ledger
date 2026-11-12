@props(['privacy' => false])
{{-- App version and where to report a problem; on every layout. --}}
<p {{ $attributes->class(['flex flex-wrap items-center gap-x-4 gap-y-1 note']) }}>
    <span class="font-mono">{{ __('Version :version', ['version' => config('ledger.version')]) }}</span>
    @if ($privacy)
        <a class="underline" href="{{ route('privacy') }}">{{ __('Privacy') }}</a>
    @endif
    <a class="underline" href="{{ config('ledger.issues_url') }}" rel="noopener noreferrer" target="_blank">{{ __('Report an issue') }}</a>
</p>
