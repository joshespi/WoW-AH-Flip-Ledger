<x-app-layout :title="__('Settings')">
    @php
        // field => [label, hint]; the limits come from the controller, which validates against the same ones.
        $numbers = [
            'target_margin_pct' => [__('Target margin (%)'), __('Sets the target sell price: average cost with this margin on top, after the :cut% AH cut.', ['cut' => \App\Support\Money::AH_CUT * 100])],
            'market_share_pct' => [__('Market share (%)'), __('The share of the region\'s sales you expect to capture. Days to sell is your stock divided by estimated sold per day times this.')],
            'stale_minutes' => [__('Warn when TSM data is older than (minutes)'), null],
        ];
    @endphp

    <x-section :title="__('Preferences')" :description="__('Times are stored in UTC and shown in your timezone.')">
        <form method="post" action="{{ route('settings.update') }}" class="mt-6 space-y-6">
            @csrf
            @method('patch')

            <div>
                <x-input-label for="timezone" :value="__('Timezone')" />
                <div class="mt-1 flex gap-2" x-data="{
                        browser: Intl.DateTimeFormat().resolvedOptions().timeZone,
                        use() { if ([...$refs.zone.options].some(o => o.value === this.browser)) $refs.zone.value = this.browser },
                    }">
                    <select id="timezone" name="timezone" x-ref="zone" class="block w-full field">
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}" @selected(old('timezone', $user->timezone) === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                    <x-secondary-button type="button" class="shrink-0" @click="use()" title="{{ __('Set this to the timezone your browser reports') }}">{{ __('Use this browser\'s') }}</x-secondary-button>
                </div>
                <x-input-error class="mt-2" :messages="$errors->get('timezone')" />
            </div>

            <div>
                <x-input-label for="region" :value="__('Region')" />
                <select id="region" name="region" class="mt-1 block w-full field">
                    @foreach ($regions as $code => $label)
                        <option value="{{ $code }}" @selected(old('region', $user->region) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-input-error class="mt-2" :messages="$errors->get('region')" />
            </div>

            @foreach ($numbers as $name => [$label, $hint])
                @php([, $min, $max, $step] = $limits[$name])
                <div>
                    <x-input-label :for="$name" :value="$label" />
                    <x-text-input :id="$name" :name="$name" type="number" :step="$step" :min="$min" :max="$max" class="mt-1 block w-full"
                                  :value="old($name, $user->{$name})" />
                    @if ($hint)
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                    @endif
                    <x-input-error class="mt-2" :messages="$errors->get($name)" />
                </div>
            @endforeach

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Price alert emails') }}</legend>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Off by default. When on, you get one email after a price refresh listing what changed, and never more than one email per item and rule in :h hours.', ['h' => config('ledger.alerts.cooldown_hours')]) }}</p>
                @foreach ([
                    'alerts_enabled' => [__('Send me price alerts'), null],
                    'alert_buy_target' => [__('A watchlist item reaches its buy target'), __('Floor at or under the target you set on the Market page.')],
                    'alert_sell_target' => [__('A watchlist item reaches its sell target'), __('Floor at or over the target you set on the Market page.')],
                    'alert_profit' => [__('A position moves into profit'), __('The floor clears break-even after the AH cut, for items you hold.')],
                ] as $field => [$label, $hint])
                    <div @class(['ms-6' => $field !== 'alerts_enabled'])>
                        <input type="hidden" name="{{ $field }}" value="0">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $user->{$field})) class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                            {{ $label }}
                        </label>
                        @if ($hint)<p class="ms-6 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>@endif
                    </div>
                @endforeach
            </fieldset>

            <div class="flex items-center gap-4">
                <x-primary-button>{{ __('Save') }}</x-primary-button>
                <x-saved :when="session('status') === 'settings-updated'" />
            </div>
        </form>
    </x-section>

    <x-section :title="__('Your data')"
               :description="__('Download everything this site holds about you as a zip of CSV files: trades, sales, expirations, cancellations, watchlist, uploads and account settings. Passwords and tokens are not included.')">
        <div class="mt-4 flex flex-wrap items-center gap-4">
            <x-primary-button :href="route('settings.export')">{{ __('Download my data') }}</x-primary-button>
            <a href="{{ route('profile.edit') }}" class="text-sm underline text-red-600 dark:text-red-400">{{ __('Delete my account') }}</a>
        </div>
    </x-section>

    <x-section :title="__('Uploader tokens')"
               :description="__('The companion uploader signs in with a token. A token can only upload your TSM data; it cannot read anything or change your account. Make one per computer so you can revoke them separately.')">
        @if ($newToken)
            <x-success class="mt-4">
                {{ __('Copy your new token now. It is shown only once.') }}
                <code class="mt-2 block break-all font-normal text-gray-900 dark:text-gray-100 select-all">{{ $newToken }}</code>
            </x-success>
        @endif

        <form method="post" action="{{ route('settings.tokens.store') }}" class="mt-6 flex items-start gap-3">
            @csrf
            <div class="grow">
                <x-input-label for="token_name" :value="__('Name')" class="sr-only" />
                <x-text-input id="token_name" name="name" type="text" class="block w-full" maxlength="60"
                              placeholder="{{ __('e.g. Gaming PC') }}" required />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>
            <x-primary-button>{{ __('Create token') }}</x-primary-button>
        </form>

        @if (session('status') === 'token-revoked')
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Token revoked.') }}</p>
        @endif

        @if ($tokens->isNotEmpty())
            <ul class="mt-6 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($tokens as $token)
                    <li class="py-3 flex items-center justify-between gap-4">
                        <div class="text-sm">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $token->name }}</p>
                            <p class="text-gray-500 dark:text-gray-400">
                                {{ __('Created :date', ['date' => $user->local($token->created_at)->format('Y-m-d')]) }}
                                ·
                                {{ $token->last_used_at
                                    ? __('last used :date', ['date' => $user->local($token->last_used_at)->format('Y-m-d H:i')])
                                    : __('never used') }}
                            </p>
                        </div>
                        <form method="post" action="{{ route('settings.tokens.destroy', $token->id) }}"
                              x-data @submit="confirm(@js(__('Revoke this token? An uploader using it will stop working.'))) || $event.preventDefault()">
                            @csrf
                            @method('delete')
                            <x-danger-button>{{ __('Revoke') }}</x-danger-button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-section>
</x-app-layout>
