<x-app-layout :title="__('Set up')" games="any">
    @php
        $labels = [
            'windows-amd64' => 'Windows (64-bit)',
            'darwin-arm64' => 'macOS (Apple Silicon)',
            'darwin-amd64' => 'macOS (Intel)',
            'linux-amd64' => 'Linux (Wine / Proton)',
        ];
        $builds = $current['builds'] ?? [];
        // "suggested" only when the browser told us its OS; the example command falls back to Windows.
        $suggested = ($guess !== null && isset($builds[$guess])) ? $guess : null;
        $pick = $suggested ?? (isset($builds['windows-amd64']) ? 'windows-amd64' : array_key_first($builds));
        $file = $pick ? $builds[$pick]['file'] : 'wowflipledger-uploader';
        $run = ($pick && str_starts_with($pick, 'windows') ? '.\\' : './').$file;
    @endphp

    <div class="max-w-3xl mx-auto space-y-6">
        <x-section :title="__('1. Download the uploader')" width="max-w-none"
                   :description="__('A small program that sends the files WoW saves (our addon\'s record and, if you use it, TradeSkillMaster\'s) to this site after each /reload or logout. It reads only those two files and writes nothing to your WoW folder unless you turn on the addon\'s data file (step 4). Prefer not to install anything? You can upload the file by hand instead.')">
            @if ($current === null)
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('The uploader has not been published on this server yet.') }}</p>
                <x-secondary-button :href="route('uploads.index')" class="mt-4">{{ __('Upload by hand') }}</x-secondary-button>
            @else
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Version :v', ['v' => $current['version']]) }}
                    @if (version_compare($current['version'], $minVersion, '<'))
                        <span class="text-red-600 dark:text-red-400">{{ __('(older than the minimum the server accepts, :min; do not use)', ['min' => $minVersion]) }}</span>
                    @endif
                </p>

                <ul class="mt-3 divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    @foreach ($labels as $key => $label)
                        @continue(! isset($builds[$key]))
                        @php($b = $builds[$key])
                        <li class="py-3">
                            <x-download-row :href="route('download.build', $key)" :size="$b['size']" :sha256="$b['sha256']">
                                {{ $label }}
                                @if ($key === $suggested)<x-chip type="suggested" tone="indigo" class="ms-2" />@endif
                            </x-download-row>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400"><a class="underline" href="{{ route('download.sums') }}">SHA256SUMS</a></p>

                <div class="mt-4 rounded-md bg-gray-100 dark:bg-gray-900 p-3 text-xs text-gray-700 dark:text-gray-300 space-y-1">
                    <p class="font-medium">{{ __('The program is not code-signed yet, so your system will warn the first time:') }}</p>
                    <p>{{ __('Windows: SmartScreen says "Windows protected your PC". Choose More info, then Run anyway.') }}</p>
                    <p>{{ __('macOS: Gatekeeper blocks it. Right-click the file and choose Open, or run: xattr -d com.apple.quarantine <file>.') }}</p>
                    <p>{{ __('You can check the download against the SHA-256 above before running it.') }}</p>
                </div>
            @endif
        </x-section>

        <x-section :title="__('2. Create a token and connect')" width="max-w-none"
                   :description="__('The token lets the uploader send data for your account and nothing else. You can revoke it in Settings.')">
            @if ($token)
                <x-success class="mt-4">{{ __('Copy this now. It is shown once.') }}</x-success>
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Open a terminal in the folder with the download and run:') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-md bg-gray-100 dark:bg-gray-900 p-3 text-xs text-gray-800 dark:text-gray-200 select-all">{{ $run }} setup --server {{ $server }} --token {{ $token }}
{{ $run }} once
{{ $run }} install</pre>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('setup saves the connection and finds your WoW folder, once uploads now, and install starts the uploader at login (Windows and macOS).') }}</p>
            @else
                <form method="post" action="{{ route('setup.token') }}" class="mt-4 flex flex-wrap items-end gap-4">
                    @csrf
                    <div>
                        <x-input-label for="name" :value="__('Token name')" />
                        <x-text-input id="name" name="name" type="text" maxlength="60" required class="mt-1 block w-64" :value="old('name', __('My PC'))" />
                        <x-input-error class="mt-2" :messages="$errors->get('name')" />
                    </div>
                    <x-primary-button>{{ __('Create token') }}</x-primary-button>
                </form>
            @endif
        </x-section>

        <x-section :title="__('3. Send your first upload')" width="max-w-none">
            <div class="mt-4">
                <livewire:setup-progress />
            </div>
        </x-section>

        <x-section :title="__('4. Optional: see it in game')" width="max-w-none"
                   :description="__('Install the addon first: it records your auction buys and sales (no TradeSkillMaster needed), and adds your cost, floor and profit to item tooltips (including the auction house), a /wfl panel, and a login alert when a watched item hits its target.')">
            @if ($addon === null)
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('The addon has not been published on this server yet.') }}</p>
            @else
                <x-download-row class="mt-4 text-sm" :href="route('download.addon')" :size="$addon['size']" :sha256="$addon['sha256']">
                    {{ __('WowFlipLedger addon :v', ['v' => $addon['version']]) }}
                </x-download-row>
            @endif

            <ol class="mt-4 list-decimal ms-5 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <li>{{ __('Unzip it so the WowFlipLedger folder sits in World of Warcraft\\_retail_\\Interface\\AddOns\\ (or _forever_ for World of Warcraft: Forever; the uploader sends each game to its own ledger).') }}</li>
                <li>{{ __('Run:') }} <code class="text-xs">{{ $run }} addon enable</code></li>
                <li>{{ __('Keep the uploader running. It refreshes the addon\'s data file about every 5 minutes and after each upload.') }}</li>
                <li>{{ __('In game, type /reload to load the new data. WoW only reads addon files at login and /reload.') }}</li>
            </ol>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('This is the one thing the uploader writes under your WoW folder: Interface\\AddOns\\WowFlipLedger\\Data.lua. It stays off until you run addon enable, never creates the folder, and leaves your notes out.') }}</p>
        </x-section>
    </div>
</x-app-layout>
