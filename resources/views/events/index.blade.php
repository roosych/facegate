<x-app-layout>
    @section('title', 'Alcohol Events')
    @section('subtitle', 'Events with alcohol concentration > 0')

    @php
        $activeTerminal = request()->integer('terminal') ?: ($terminals->first()?->id ?? '');
        $dateFrom = request('date_from', now()->format('Y-m-d'));
        $dateTo   = request('date_to',   now()->format('Y-m-d'));
        $hasFilters = request()->hasAny(['terminal', 'date_from', 'date_to', 'employee']);
    @endphp

    @if($terminals->isNotEmpty())
    <div
        x-data="{
            terminal: '{{ $activeTerminal }}',
            start: '{{ $dateFrom }}',
            end: '{{ $dateTo }}',
            loading: false,
            jobStatus: null,
            pollTimer: null,
            get statusText() {
                if (!this.jobStatus) return '';
                const s = this.jobStatus.status;
                if (s === 'queued')  return 'Queued...';
                if (s === 'running') return 'Importing... saved: ' + this.jobStatus.imported + ' / fetched: ' + this.jobStatus.total;
                if (s === 'done')    return 'Done: ' + this.jobStatus.imported + ' new events (' + this.jobStatus.total + ' fetched from terminal)';
                if (s === 'failed')  return 'Error: ' + (this.jobStatus.message || 'unknown error');
                return '';
            },
            get statusColor() {
                if (!this.jobStatus) return 'text-gray-400';
                const s = this.jobStatus.status;
                if (s === 'done')   return this.jobStatus.imported > 0 ? 'text-green-600' : 'text-gray-500';
                if (s === 'failed') return 'text-red-600';
                return 'text-indigo-500';
            },
            async doImport() {
                this.loading = true;
                this.jobStatus = null;
                clearInterval(this.pollTimer);
                const body = new URLSearchParams({ start: this.start + ' 00:00:00', end: this.end + ' 23:59:59' });
                await window.fetch('/events/fetch/' + this.terminal, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body,
                });
                this.startPolling();
            },
            startPolling() {
                const check = async () => {
                    const res = await window.fetch('/events/fetch/' + this.terminal + '/status');
                    this.jobStatus = await res.json();
                    if (this.jobStatus.status === 'done') {
                        this.loading = false;
                        clearInterval(this.pollTimer);
                        if (this.jobStatus.imported > 0) {
                            setTimeout(() => {
                                const url = new URL(window.location);
                                url.searchParams.set('terminal', this.terminal);
                                url.searchParams.set('date_from', this.start);
                                url.searchParams.set('date_to', this.end);
                                window.location = url.toString();
                            }, 1000);
                        }
                    } else if (this.jobStatus.status === 'failed') {
                        this.loading = false;
                        clearInterval(this.pollTimer);
                    }
                };
                check();
                this.pollTimer = setInterval(check, 2000);
            }
        }"
        class="bg-white rounded-lg border border-gray-200 px-5 py-4 mb-5"
    >
        <form
            method="GET"
            action="{{ route('events.index') }}"
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="
                $el.terminal.value = terminal;
                $el.date_from.value = start;
                $el.date_to.value = end;
                $el.submit();
            "
        >
            {{-- Terminal --}}
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Terminal</label>
                <select
                    x-model="terminal"
                    name="terminal"
                    class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-1 focus:ring-indigo-400"
                >
                    @foreach($terminals as $t)
                        <option value="{{ $t->id }}">{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Date from --}}
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
                <input
                    type="date"
                    x-model="start"
                    name="date_from"
                    class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                >
            </div>

            {{-- Date to --}}
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
                <input
                    type="date"
                    x-model="end"
                    name="date_to"
                    class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                >
            </div>

            {{-- Employee search --}}
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Employee</label>
                <input
                    type="text"
                    name="employee"
                    value="{{ request('employee') }}"
                    placeholder="Name or code"
                    class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                >
            </div>

            {{-- Buttons --}}
            <div class="flex items-end gap-2">
                <div class="flex flex-col">
                    <label class="block text-xs font-medium text-transparent mb-1">-</label>
                    <button
                        type="submit"
                        class="px-4 py-1.5 text-sm font-medium text-white rounded-lg transition-colors"
                        style="background-color:#4f46e5"
                    >
                        Show
                    </button>
                </div>

                <div class="flex flex-col">
                    <label class="block text-xs font-medium text-transparent mb-1">-</label>
                    <button
                        type="button"
                        @click="doImport()"
                        :disabled="loading"
                        class="flex items-center gap-1.5 px-4 py-1.5 text-sm font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-lg transition-colors disabled:opacity-50"
                    >
                        <svg x-show="!loading" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <svg x-show="loading" x-cloak class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        <span x-text="loading ? 'Importing...' : 'Import'"></span>
                    </button>
                </div>

                @if($hasFilters)
                    <div class="flex flex-col">
                        <label class="block text-xs font-medium text-transparent mb-1">-</label>
                        <a href="{{ route('events.index') }}" class="py-1.5 text-sm text-gray-400 hover:text-gray-600">Clear</a>
                    </div>
                @endif
            </div>

            {{-- Job status --}}
            <div x-show="jobStatus !== null" x-cloak class="w-full mt-1">
                <span class="text-xs" :class="statusColor" x-text="statusText"></span>
            </div>
        </form>
    </div>
    @endif

    {{-- Stats --}}
    <div class="flex items-center justify-between mb-3">
        <p class="text-sm text-gray-500">{{ number_format($events->total()) }} event(s) with alcohol > 0</p>
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Time</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Employee</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Terminal</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Alcohol</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($events as $event)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                            {{ $event->event_time->format('d.m.Y H:i:s') }}
                        </td>

                        <td class="px-4 py-3 text-sm">
                            @if($event->employee)
                                <a href="{{ route('employees.show', $event->employee) }}"
                                    class="text-indigo-600 hover:text-indigo-800 font-medium">
                                    {{ $event->employee->full_name }}
                                </a>
                            @elseif($event->raw_data['name'] ?? null)
                                <span class="text-gray-700">{{ $event->raw_data['name'] }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-600">
                            {{ $event->hikvisionTerminal?->name ?? '—' }}
                        </td>

                        <td class="px-4 py-3 text-sm font-semibold {{ $event->alcoholPassed() ? 'text-orange-500' : 'text-red-600' }}">
                            {{ $event->alcoholPromille() !== null ? number_format($event->alcoholPromille(), 2) . ' ‰' : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-sm text-gray-400">
                            No events with alcohol found.
                            @if($hasFilters)
                                <a href="{{ route('events.index') }}" class="text-indigo-500 hover:text-indigo-700 ml-1">Clear filters</a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($events->hasPages())
        <div class="mt-4">{{ $events->links() }}</div>
    @endif
</x-app-layout>
