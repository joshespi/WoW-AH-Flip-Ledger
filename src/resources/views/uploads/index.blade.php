<x-app-layout :title="__('Uploads')" games="any">
    @php($user = auth()->user())

    <x-section :title="__('Upload your saved data')"
               :description="__('Choose WowFlipLedger.lua (from our addon) or TradeSkillMaster.lua from WTF/Account/<your account>/SavedVariables. The game only writes them when you /reload or log out, so do that first. The file is read once and not kept; only your sales, expirations, cancellations, buy suggestions and item counts are stored.')">
        @if (session('status') === 'upload-ok')
            <x-success class="mt-4">{{ __('Upload processed.') }}</x-success>
        @elseif (session('status') === 'upload-undone')
            <x-success class="mt-4">
                {{ __('Upload undone: :n rows removed.', ['n' => number_format(session('undone.rows', 0))]) }}
                {{ session('undone.inventory')
                    ? __('Inventory for that game was cleared; upload your correct file to refill it.')
                    : __('Inventory was left as it was.') }}
            </x-success>
        @endif
        <x-input-error class="mt-4" :messages="$errors->get('undo')" />

        <form method="post" action="{{ route('uploads.store') }}" enctype="multipart/form-data"
              class="mt-6 space-y-4" x-data>
            @csrf
            <input type="hidden" name="file_mtime" x-ref="mtime">

            <div>
                <x-input-label for="file" :value="__('WowFlipLedger.lua or TradeSkillMaster.lua')" />
                <input id="file" name="file" type="file" required accept=".lua,.gz"
                       class="mt-1 block w-full text-sm text-gray-700 dark:text-gray-300"
                       @change="$refs.mtime.value = $event.target.files[0]?.lastModified ?? ''">
                <x-input-error class="mt-2" :messages="$errors->get('file')" />
                <x-input-error class="mt-2" :messages="$errors->get('file_mtime')" />
            </div>

            {{-- A TSM file does not say which game it came from, so the file's folder decides. One enabled game: nothing to ask. --}}
            @if ($user->playsSeveral())
                <fieldset>
                    <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Which game is this file from?') }}</legend>
                    <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300">
                        @foreach ($user->enabledGames() as $value)
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" name="game" value="{{ $value }}" @checked(old('game', $user->activeGame()) === $value)>
                                {{ \App\Support\Game::label($value) }} <span class="text-gray-500">({{ \App\Support\Game::FOLDERS[$value] }})</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @else
                <input type="hidden" name="game" value="{{ $user->activeGame() }}">
            @endif

            @error('game')
                <div>
                    <x-input-error :messages="$errors->get('game')" />
                    @if (session('game_check'))
                        <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="game_confirmed" value="1">
                            {{ __('Upload anyway (choose the file again)') }}
                        </label>
                    @endif
                </div>
            @enderror

            <x-primary-button>{{ __('Upload') }}</x-primary-button>
        </form>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Prefer automatic uploads?') }}
            <a class="underline" href="{{ route('setup.show') }}">{{ __('Set up the uploader') }}</a>.
        </p>
    </x-section>

    <x-section :title="__('Recent uploads')" width="max-w-none"
               :description="__('Uploaded to the wrong game? The newest upload of each game can be undone, which removes the rows it added. Older uploads cannot, because newer files repeat their history.')">
        @if ($uploads->isEmpty())
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing uploaded yet.') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Received') }}</th>
                            <th scope="col">{{ __('TSM saved') }}</th>
                            @if ($user->playsSeveral())<th scope="col">{{ __('Game') }}</th>@endif
                            <th scope="col">{{ __('Via') }}</th>
                            <th scope="col">{{ __('Result') }}</th>
                            <th scope="col">{{ __('New rows') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Undo') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($uploads as $upload)
                            <tr class="align-top">
                                <td>{{ $user->local($upload->received_at)->format('Y-m-d H:i') }}</td>
                                <td>{{ $user->local($upload->file_mtime)?->format('Y-m-d H:i') ?? '—' }}</td>
                                @if ($user->playsSeveral())<td><x-game-badge :game="$upload->game" /></td>@endif
                                <td>{{ $upload->source }}{{ $upload->client_version ? ' '.$upload->client_version : '' }}</td>
                                <td class="whitespace-normal">
                                    @if ($upload->failed())
                                        <x-chip type="Failed" tone="red" />
                                        <span class="block mt-1 text-gray-600 dark:text-gray-400 break-words">{{ $upload->error }}</span>
                                    @else
                                        <x-chip type="OK" tone="green" />
                                    @endif
                                </td>
                                <td class="text-gray-700 dark:text-gray-300">{{ \App\Models\Upload::summary($upload->counts) }}</td>
                                <td class="whitespace-normal">
                                    @if (in_array($upload->id, $undoable, true))
                                        <form method="post" action="{{ route('uploads.destroy', $upload->id) }}" class="space-y-2" x-data
                                              @submit="confirm(@js(__('Remove the rows this upload added?'))) || $event.preventDefault()">
                                            @csrf
                                            @method('DELETE')
                                            <label class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-400">
                                                <input type="checkbox" name="clear_inventory" value="1" checked class="mt-0.5">
                                                {{ __('Also clear this game\'s inventory snapshot') }}
                                            </label>
                                            <x-danger-button>{{ __('Undo upload') }}</x-danger-button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-section>
</x-app-layout>
