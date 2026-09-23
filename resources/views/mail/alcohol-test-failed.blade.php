<x-mail::message>
# Провален тест на алкоголь

**Сотрудник:** {{ $employeeName }}

**Терминал:** {{ $terminalName }}

**Время:** {{ $eventTime->format('d.m.Y H:i:s') }}

**Результат:** {{ $result }}

@if($concentration !== null)
**Концентрация:** {{ $concentration }} мг/100мл ({{ $promille }} ‰)
@endif

С уважением,<br>
{{ config('app.name') }}
</x-mail::message>
