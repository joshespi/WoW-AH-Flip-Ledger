<x-app-layout :title="__('Import trades')">
    <x-section :title="__('Your buys')" width="max-w-2xl"
               :description="__('The ledger costs your stock from the buys you give it (TSM\'s buy log double-counts commodity lots, so it is not used). Import them as a CSV with one buy per row. Importing the same file twice adds nothing.')">
        @if (session('imported'))
            @php($r = session('imported'))
            <x-success class="mt-4">
                {{ __(':new buys added, :dup already imported.', ['new' => number_format($r['new']), 'dup' => number_format($r['duplicates'])]) }}
                <a class="underline" href="{{ route('dashboard') }}">{{ __('Open the dashboard') }}</a>
            </x-success>
        @endif

        <div class="mt-6 flex items-baseline justify-between gap-4">
            <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Format') }}</h4>
            <a href="{{ route('trades.import.template') }}" class="link text-sm">{{ __('Download a template') }}</a>
        </div>
        <pre class="mt-2 overflow-x-auto code-block text-gray-800 dark:text-gray-200">{{ \App\Services\TradesImportService::TEMPLATE }}</pre>
        <ul class="mt-3 list-disc ps-5 space-y-1 text-sm text-gray-600 dark:text-gray-400">
            <li>{{ __('time: ISO date and time. Without an offset it is read in your timezone (:tz).', ['tz' => auth()->user()->timezone]) }}</li>
            <li>{{ __('item: i:ID, or an exact item name that matches one item (names are known once you have uploaded TSM data or prices have been fetched).') }}</li>
            <li>{{ __('cost: the lot total, not per unit: 2730.82 (gold) or 2,730g 82s.') }}</li>
            <li>{{ __('Up to :rows rows and :size at a time. Nothing is saved if any row has a problem.', ['rows' => number_format(\App\Services\TradesImportService::MAX_ROWS), 'size' => \Illuminate\Support\Number::fileSize(\App\Services\TradesImportService::MAX_BYTES)]) }}</li>
        </ul>

        <form method="post" action="{{ route('trades.import.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
            @csrf

            <div>
                <x-input-label for="csv" :value="__('CSV file')" />
                <input id="csv" name="csv" type="file" accept=".csv,text/csv,text/plain"
                       class="field-file">
                <x-input-error class="mt-2" :messages="$errors->get('csv')" />
            </div>

            <div>
                <x-input-label for="csv_text" :value="__('...or paste it here')" />
                <textarea id="csv_text" name="csv_text" rows="6" spellcheck="false"
                          class="mt-1 block w-full field font-mono text-xs">{{ old('csv_text') }}</textarea>
                <x-input-error class="mt-2" :messages="$errors->get('csv_text')" />
            </div>

            <x-primary-button>{{ __('Import') }}</x-primary-button>
        </form>
    </x-section>
</x-app-layout>
