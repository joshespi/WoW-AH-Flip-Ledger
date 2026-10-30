{{-- An item's name (or "Item #id" while it has none), linked to its page. --}}
@props(['id', 'name' => null])

<a href="{{ route('items.show', $id) }}" {{ $attributes->class(['font-medium text-gray-900 hover:text-indigo-500 dark:text-gray-100 dark:hover:text-indigo-300']) }}>{{ \App\Support\ItemName::display($name, (int) $id) }}</a>
