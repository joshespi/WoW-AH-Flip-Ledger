<x-app-layout :title="__('Uploads')">
    @php($user = auth()->user())

    <x-section :title="__('Upload your TSM data')"
               :description="__('Choose TradeSkillMaster.lua from WTF/Account/<your account>/SavedVariables. TSM only writes it when you /reload or log out, so do that in game first. The file is read once and not kept; only your sales, expirations, cancellations and item counts are stored.')">
        @if (session('status') === 'upload-ok')
            <x-success class="mt-4">{{ __('Upload processed.') }}</x-success>
        @endif

        <form method="post" action="{{ route('uploads.store') }}" enctype="multipart/form-data"
              class="mt-6 space-y-4" x-data>
            @csrf
            <input type="hidden" name="file_mtime" x-ref="mtime">

            <div>
                <x-input-label for="file" :value="__('TradeSkillMaster.lua')" />
                <input id="file" name="file" type="file" required accept=".lua,.gz"
                       class="mt-1 block w-full text-sm text-gray-700 dark:text-gray-300"
                       @change="$refs.mtime.value = $event.target.files[0]?.lastModified ?? ''">
                <x-input-error class="mt-2" :messages="$errors->get('file')" />
                <x-input-error class="mt-2" :messages="$errors->get('file_mtime')" />
            </div>

            <x-primary-button>{{ __('Upload') }}</x-primary-button>
        </form>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Prefer automatic uploads?') }}
            <a class="underline" href="{{ route('setup.show') }}">{{ __('Set up the uploader') }}</a>.
        </p>
    </x-section>

    <x-section :title="__('Recent uploads')" width="max-w-none">
        @if ($uploads->isEmpty())
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing uploaded yet.') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Received') }}</th>
                            <th scope="col">{{ __('TSM saved') }}</th>
                            <th scope="col">{{ __('Via') }}</th>
                            <th scope="col">{{ __('Result') }}</th>
                            <th scope="col">{{ __('New rows') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($uploads as $upload)
                            <tr class="align-top">
                                <td>{{ $user->local($upload->received_at)->format('Y-m-d H:i') }}</td>
                                <td>{{ $user->local($upload->file_mtime)?->format('Y-m-d H:i') ?? '—' }}</td>
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
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-section>
</x-app-layout>
