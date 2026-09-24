<x-mail::message>
# Терминал требует чистки/калибровки

**Терминал:** {{ $terminalName }}

**Проверок с последней чистки:** {{ $testCount }} (порог: {{ $threshold }})

@if($lastCleanedAt !== null)
**Последняя чистка:** {{ $lastCleanedAt->format('d.m.Y H:i:s') }}
@else
Чистка ещё не отмечалась.
@endif

Отметить чистку выполненной можно на панели управления.

С уважением,<br>
{{ config('app.name') }}
</x-mail::message>
