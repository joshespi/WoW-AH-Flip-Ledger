{{-- The `<th>`s of a `sortableTable`: `cols` is a list of [label, 'num'|'text', tooltip?], labels
     already translated. Put any unsortable columns after it in the same row. --}}
@props(['cols'])

@foreach ($cols as $i => $col)
    @php [$label, $type, $tip] = $col + [2 => null]; @endphp
    <th scope="col" class="{{ $type === 'num' ? 'num' : '' }}" :aria-sort="ariaSort({{ $i }})" @if ($tip) title="{{ $tip }}" @endif>
        <button type="button" @click="sort({{ $i }}, '{{ $type }}')"
                class="font-medium select-none rounded hover:text-gray-900 dark:hover:text-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">{{ $label }}<span aria-hidden="true" x-text="arrow({{ $i }})"></span></button>
    </th>
@endforeach
