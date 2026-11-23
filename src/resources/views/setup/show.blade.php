<x-app-layout :title="__('Set up')" games="any">
    <div class="max-w-3xl mx-auto space-y-6">
        <x-section :title="__('1. Install the addon')" width="max-w-none"
                   :description="__('The addon records your auction buys, sales and expiries as you play (no TradeSkillMaster needed). It saves them to a file when you log out or /reload.')">
            <x-primary-button :href="$addonUrl" class="mt-4" target="_blank" rel="noopener">{{ __('Get the addon on CurseForge') }}</x-primary-button>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Inside your World of Warcraft installation, choose the folder for your game (:folders). Install it with the CurseForge app, or unzip it so the WowFlipLedger folder sits under Interface\AddOns.', ['folders' => implode(', ', \App\Support\Game::FOLDERS)]) }}</p>
        </x-section>

        <x-section :title="__('2. Upload your file')" width="max-w-none"
                   :description="__('After you log out or /reload, upload the addon\'s file (and TradeSkillMaster\'s, if you use it) from your WoW folder. Pick the game it came from.')">
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('In the same game folder, look under WTF\Account\<your account>\SavedVariables for WowFlipLedger.lua or TradeSkillMaster.lua.') }}</p>
            <x-primary-button :href="route('uploads.index')" class="mt-4">{{ __('Go to uploads') }}</x-primary-button>
        </x-section>

        <x-section :title="__('3. Check it arrived')" width="max-w-none">
            <div class="mt-4">
                <livewire:setup-progress />
            </div>
        </x-section>
    </div>
</x-app-layout>
