@php($me = auth()->user())

<div class="mt-3 overflow-x-auto">
    <table class="data-table">
        <caption class="sr-only">{{ __('Uploads, newest first.') }}</caption>
        <thead>
            <tr>
                <th scope="col">{{ __('Received') }}</th>
                <th scope="col">{{ __('User') }}</th>
                <th scope="col">{{ __('Source') }}</th>
                <th scope="col">{{ __('Status') }}</th>
                <th scope="col" class="num">{{ __('Size') }}</th>
                <th scope="col">{{ __('Client') }}</th>
                <th scope="col">{{ __('Result') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($uploads as $u)
                <tr>
                    <td class="whitespace-nowrap">{{ $me->local($u->received_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ $u->email }}</td>
                    <td>{{ $u->source }}</td>
                    <td><span class="{{ $u->status === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $u->status }}</span></td>
                    <td class="num">{{ \Illuminate\Support\Number::fileSize((int) $u->bytes, 1) }}</td>
                    <td>{{ $u->client_version ?? '—' }}</td>
                    <td class="text-gray-600 dark:text-gray-400 break-words max-w-md">{{ $u->error ?? $u->summary }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
