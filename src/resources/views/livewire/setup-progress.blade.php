{{-- Polls every 3 s, only while the tab is visible and only while waiting; once an upload has
     arrived the attribute is gone. --}}
<div @unless ($first) wire:poll.visible.3s @endunless aria-live="polite">
    @if ($first)
        <x-success>
            {{ __('First upload received :when. Your ledger is ready.', ['when' => $user->local($first->received_at)->format('Y-m-d H:i')]) }}
            <a class="underline" href="{{ route('dashboard') }}">{{ __('Open the dashboard') }}</a>
        </x-success>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-400">
            <span class="inline-block h-2 w-2 rounded-full bg-amber-500 animate-pulse align-middle me-2" aria-hidden="true"></span>{{ __('Waiting for your first upload. Run the uploader, then /reload in game. This page updates by itself.') }}
        </p>
        @if ($failed)
            <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ __('The last upload was rejected: :error', ['error' => $failed->error]) }}</p>
        @endif
    @endif
</div>
