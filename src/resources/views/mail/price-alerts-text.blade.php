@php
    $titles = ['buy_target' => ['At or under your buy target', 'target'], 'sell_target' => ['At or over your sell target', 'target'], 'profit' => ['Now in profit after the AH cut', 'break-even']];
@endphp
{{ config('app.name') }}
{{ __('Prices have moved on items you asked about. Floors are per unit.') }}
@foreach ($groups as $kind => $items)

{{ __($titles[$kind][0]) }}
@foreach ($items as $a)
- {!! $a['name'] !!}: {{ __('floor') }} {{ \App\Support\Money::fmt($a['floor']) }}, {{ __($titles[$kind][1]) }} {{ \App\Support\Money::fmt($a['target']) }} ({{ route('items.show', $a['item_id']) }})
@endforeach
@endforeach

{{ __('Change which alerts you get:') }} {{ $settings }}
{{ __('Turn alerts off:') }} {{ $unsubscribe }}
