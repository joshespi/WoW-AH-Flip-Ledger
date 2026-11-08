<x-section :title="__('Public scoreboard')" :description="__('Off unless you turn it on. When it is on, your nickname (never your name or email) and your realized P/L, sales, units and flips are public.')">
    @if ($user->hasVerifiedEmail())
        <form method="post" action="{{ route('profile.scoreboard') }}" class="mt-6 space-y-6">
            @csrf
            @method('patch')

            <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                <input type="checkbox" name="scoreboard_opt_in" value="1" @checked(old('scoreboard_opt_in', $user->scoreboard_opt_in)) class="rounded">
                {{ __('Show me on the public scoreboard') }}
            </label>

            <div>
                <x-input-label for="scoreboard_nickname" :value="__('Nickname')" />
                <x-text-input id="scoreboard_nickname" name="scoreboard_nickname" type="text" class="mt-1 block w-full" :value="old('scoreboard_nickname', $user->scoreboard_nickname)" maxlength="24" autocomplete="off" />
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Shown instead of your name. 3 to 24 letters, numbers, spaces, dots, dashes or underscores. Do not use a character name if you want to stay anonymous.') }}</p>
                <x-input-error class="mt-2" :messages="$errors->get('scoreboard_nickname')" />
            </div>

            <div class="flex items-center gap-4">
                <x-primary-button>{{ __('Save') }}</x-primary-button>
                <x-saved :when="session('status') === 'scoreboard-updated'" />
                <a href="{{ route('scoreboard') }}" class="text-sm underline text-gray-600 dark:text-gray-400">{{ __('View the scoreboard') }}</a>
            </div>
        </form>
    @else
        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Verify your email address to join.') }}</p>
    @endif
</x-section>
