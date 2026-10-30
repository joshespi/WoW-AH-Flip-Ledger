@if ($problems)
Needs attention:
@foreach ($problems as $label => $what)
- {{ $label }}: {!! $what !!}
@endforeach
@endif
@if ($recovered)

Back to normal:
@foreach ($recovered as $label)
- {{ $label }}
@endforeach
@endif

Details: {{ $admin }}
You get this because you are an admin. A problem is reported once, then again every {{ config('ledger.health.reminder_hours') }} hours while it lasts.
