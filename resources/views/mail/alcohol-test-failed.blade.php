<x-mail::message>
# Alcohol test failed

**Employee:** {{ $employeeName }}

**Terminal:** {{ $terminalName }}

**Time:** {{ $eventTime->format('d.m.Y H:i:s') }}

**Result:** {{ $result }}

@if($concentration !== null)
**Concentration:** {{ $concentration }} mg/100ml ({{ $promille }} ‰)
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
