{{-- Placeholder for a derived metric that doesn't have enough price history yet. --}}
@props(['days' => 0])

<span class="text-xs italic text-gray-400 dark:text-gray-500"
      title="{{ __('Estimates need :min days of price history; this item has :days.', ['min' => \App\Support\MarketStats::minDays(), 'days' => $days]) }}">
    {{ __('collecting data (:days/:min)', ['days' => $days, 'min' => \App\Support\MarketStats::minDays()]) }}
</span>
