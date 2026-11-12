@php
    $titles = [
        'buy_target' => ['At or under your buy target', 'Target'],
        'sell_target' => ['At or over your sell target', 'Target'],
        'profit' => ['Now in profit after the AH cut', 'Break-even'],
    ];
@endphp
<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#eeeff2;font-family:Arial,Helvetica,sans-serif;color:#1c1d22;">
    <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="font-size:18px;margin:0 0 4px;">{{ config('app.name') }}</h1>
        <p style="margin:0 0 16px;color:#555a68;font-size:14px;">{{ __('Prices have moved on items you asked about. Floors are per unit.') }}</p>

        @foreach ($groups as $kind => $items)
            <h2 style="font-size:15px;margin:20px 0 8px;">{{ __($titles[$kind][0]) }}</h2>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <tr style="text-align:left;color:#737886;">
                    <th style="padding:4px 0;">{{ __('Item') }}</th>
                    <th style="padding:4px 0;text-align:right;">{{ __('Floor') }}</th>
                    <th style="padding:4px 0;text-align:right;">{{ __($titles[$kind][1]) }}</th>
                </tr>
                @foreach ($items as $a)
                    <tr style="border-top:1px solid #dcdee4;">
                        <td style="padding:6px 0;"><a href="{{ route('items.show', $a['item_id']) }}" style="color:#965d12;">{{ $a['name'] }}</a></td>
                        <td style="padding:6px 0;text-align:right;">{{ \App\Support\Money::fmt($a['floor']) }}</td>
                        <td style="padding:6px 0;text-align:right;">{{ \App\Support\Money::fmt($a['target']) }}</td>
                    </tr>
                @endforeach
            </table>
        @endforeach

        <p style="margin:24px 0 0;font-size:12px;color:#737886;">
            {{ __('You get this because price alerts are on for your account.') }}
            <a href="{{ $settings }}" style="color:#737886;">{{ __('Change which alerts you get') }}</a> ·
            <a href="{{ $unsubscribe }}" style="color:#737886;">{{ __('Turn alerts off') }}</a>
        </p>
    </div>
</body>
</html>
