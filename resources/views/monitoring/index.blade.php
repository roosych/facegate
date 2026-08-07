<x-app-layout>
    @section('subtitle', 'Sync runs, durations and outcomes')
    @section('title', 'Monitoring')

    @php
        $kinds = [
            \App\Models\SyncRun::KIND_RUSGUARD  => 'RusGuard',
            \App\Models\SyncRun::KIND_HIKVISION => 'Hikvision',
            \App\Models\SyncRun::KIND_TURNSTILE => 'Access point',
        ];
        $triggerStyles = [
            \App\Models\SyncRun::TRIGGER_SCHEDULE => 'bg-gray-100 text-gray-600',
            \App\Models\SyncRun::TRIGGER_AUDIT    => 'bg-amber-100 text-amber-700',
            \App\Models\SyncRun::TRIGGER_MANUAL   => 'bg-indigo-100 text-indigo-700',
            \App\Models\SyncRun::TRIGGER_CONSOLE  => 'bg-gray-100 text-gray-600',
        ];
    @endphp

    {{-- Integration health: the signals that go quiet without anything failing --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 mb-6 overflow-hidden">
        <div class="px-5 py-3 border-b border-gray-100">
            <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Integration health</h2>
        </div>

        <div class="divide-y divide-gray-50">
            <div class="flex flex-wrap items-center gap-x-8 gap-y-1 px-5 py-3">
                <span class="flex items-center gap-2 text-sm font-medium text-gray-900">
                    <span class="w-2 h-2 rounded-full {{ $health['rusguard']['audit_stale'] ? 'bg-red-400' : 'bg-emerald-400' }}"></span>
                    RusGuard
                </span>
                <span class="text-sm text-gray-500">
                    audit poll <span class="text-gray-900">{{ $health['rusguard']['audit_polled'] ?? 'never' }}</span>
                </span>
                <span class="text-sm text-gray-500">
                    last full sync <span class="text-gray-900">{{ $health['rusguard']['last_sync'] ?? 'never' }}</span>
                    @if($health['rusguard']['last_sync_duration'])
                        <span class="text-gray-400">({{ $health['rusguard']['last_sync_duration'] }})</span>
                    @endif
                </span>
                @if($health['rusguard']['audit_stale'])
                    <span class="text-sm text-red-600">audit poller has not run in the last 5 minutes</span>
                @endif
            </div>

            @foreach($health['terminals'] as $terminal)
                <div class="flex flex-wrap items-center gap-x-8 gap-y-1 px-5 py-3">
                    <span class="flex items-center gap-2 text-sm font-medium text-gray-900">
                        <span class="w-2 h-2 rounded-full {{ $terminal['push_stale'] ? 'bg-red-400' : 'bg-emerald-400' }}"></span>
                        {{ $terminal['name'] }}
                    </span>
                    <span class="text-sm text-gray-500">
                        last push <span class="text-gray-900">{{ $terminal['last_push_at'] ?? 'never' }}</span>
                    </span>
                    <span class="text-sm text-gray-500">
                        last event <span class="text-gray-900">{{ $terminal['last_event_at'] ?? 'never' }}</span>
                    </span>
                    <span class="text-sm text-gray-500">
                        last sync <span class="text-gray-900">{{ $terminal['last_sync_at'] ?? 'never' }}</span>
                    </span>
                    @if($terminal['push_stale'])
                        <span class="text-sm text-red-600">no push for over an hour — events are only arriving via the 30-minute poll</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Queue, refreshed in place --}}
    <div
        class="bg-white rounded-lg shadow border border-gray-200 mb-6 overflow-hidden"
        x-data="{
            queue: @js($queue),
            async poll() {
                try {
                    const res = await fetch('{{ route('monitoring.status') }}');
                    this.queue = (await res.json()).queue;
                } catch (e) {
                    // network blip — retry
                }
                setTimeout(() => this.poll(), this.queue.reserved > 0 ? 3000 : 10000);
            },
            init() { setTimeout(() => this.poll(), 5000); }
        }"
    >
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
            <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Queue</h2>
            <span class="text-xs text-gray-400">
                <span x-text="queue.reserved"></span> in progress ·
                <span x-text="queue.pending.reduce((sum, row) => sum + row.count, 0)"></span> waiting
            </span>
        </div>

        <template x-if="queue.pending.length === 0 && queue.failed.length === 0">
            <div class="px-5 py-4 text-sm text-gray-400">Queue is empty.</div>
        </template>

        <template x-for="row in queue.pending" :key="row.job">
            <div class="flex items-center gap-3 px-5 py-2.5 border-b border-gray-50">
                <span class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                <span class="text-sm text-gray-900" x-text="row.job"></span>
                <span class="text-sm text-gray-500">×<span x-text="row.count"></span></span>
                <span class="ml-auto text-xs text-gray-400">queued <span x-text="row.waiting_since"></span></span>
            </div>
        </template>

        <template x-if="queue.failed.length > 0">
            <div>
                <div class="px-5 py-2 bg-red-50 text-xs font-semibold text-red-700 uppercase tracking-wide">
                    Failed · <span x-text="queue.failed.length"></span>
                </div>
                <template x-for="job in queue.failed" :key="job.uuid">
                    <div class="flex items-start gap-3 px-5 py-3 border-b border-gray-50">
                        <div class="min-w-0">
                            <div class="text-sm text-gray-900" x-text="job.job"></div>
                            <div class="text-xs text-gray-400" x-text="job.failed_at"></div>
                            <div class="text-xs text-red-600 mt-0.5 break-words" x-text="job.error"></div>
                        </div>
                        <div class="ml-auto flex gap-2 flex-shrink-0">
                            <form method="POST" :action="'{{ url('monitoring/failed-jobs') }}/' + job.uuid + '/retry'">
                                @csrf
                                <button type="submit" class="px-2 py-1 text-xs font-medium text-indigo-700 bg-indigo-50 rounded hover:bg-indigo-100">Retry</button>
                            </form>
                            <form method="POST" :action="'{{ url('monitoring/failed-jobs') }}/' + job.uuid + '/forget'">
                                @csrf
                                <button type="submit" class="px-2 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200">Forget</button>
                            </form>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- People the sync gave up on --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 mb-6 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
            <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Problems</h2>
            @if($problems['without_card'] > 0)
                <span class="text-xs text-gray-500">{{ $problems['without_card'] }} active employees without a card</span>
            @endif
        </div>

        @foreach($problems['terminals'] as $terminal)
            @php $total = count($terminal['no_photo']) + count($terminal['refused']); @endphp

            <div class="px-5 py-4 border-b border-gray-50 last:border-b-0">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-900">{{ $terminal['name'] }}</span>
                    @if($total > 0)
                        <form method="POST" action="{{ route('monitoring.face-problems.clear', $terminal['id']) }}">
                            @csrf
                            <button type="submit" class="px-2 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200">
                                Retry all on next sync
                            </button>
                        </form>
                    @endif
                </div>

                @if($total === 0 && $terminal['alcohol_failed'] === 0)
                    <p class="text-sm text-gray-400">Everyone linked to this terminal has a face and a card.</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
                                No photo in RusGuard · {{ count($terminal['no_photo']) }}
                            </div>
                            @forelse($terminal['no_photo'] as $person)
                                <div class="text-sm text-gray-600">
                                    <span class="font-mono text-xs text-gray-400">{{ $person['emp_code'] }}</span>
                                    {{ $person['name'] }}
                                </div>
                            @empty
                                <div class="text-sm text-gray-400">—</div>
                            @endforelse
                        </div>

                        <div>
                            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
                                Refused by the terminal · {{ count($terminal['refused']) }}
                            </div>
                            @forelse($terminal['refused'] as $person)
                                <div class="text-sm text-gray-600">
                                    <span class="font-mono text-xs text-gray-400">{{ $person['emp_code'] }}</span>
                                    {{ $person['name'] }}
                                </div>
                            @empty
                                <div class="text-sm text-gray-400">—</div>
                            @endforelse
                        </div>
                    </div>

                    @if($terminal['alcohol_failed'] > 0)
                        <p class="mt-3 text-sm text-amber-700">{{ $terminal['alcohol_failed'] }} alcohol skip flag(s) failed to write on the last sync.</p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- Last 24 hours, per kind --}}
    <div class="grid gap-4 mb-6 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($summary as $row)
            <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
                <div class="flex items-baseline justify-between">
                    <span class="text-sm font-semibold text-gray-900">{{ $kinds[$row['kind']] ?? $row['kind'] }}</span>
                    <span class="text-xs text-gray-400">24h</span>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl font-semibold text-gray-900">{{ $row['runs'] }}</span>
                    <span class="text-sm text-gray-500">runs</span>
                    @if($row['failed'] > 0)
                        <span class="ml-auto inline-flex px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700 rounded-full">
                            {{ $row['failed'] }} failed
                        </span>
                    @endif
                </div>
                <div class="mt-1 text-xs text-gray-500">
                    avg {{ \App\Models\SyncRun::formatDuration($row['avg_ms']) ?? '—' }}
                    · max {{ \App\Models\SyncRun::formatDuration($row['max_ms']) ?? '—' }}
                </div>
            </div>
        @empty
            <div class="sm:col-span-2 lg:col-span-3 bg-white rounded-lg shadow border border-gray-200 p-4 text-sm text-gray-400">
                No sync runs in the last 24 hours.
            </div>
        @endforelse
    </div>

    {{-- Kind filter --}}
    <div class="flex items-center gap-2 mb-4">
        <a href="{{ route('monitoring.index') }}"
           class="px-3 py-1 rounded-md text-sm font-medium {{ $kind === '' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100' }}">
            All
        </a>
        @foreach($kinds as $value => $label)
            <a href="{{ route('monitoring.index', ['kind' => $value]) }}"
               class="px-3 py-1 rounded-md text-sm font-medium {{ $kind === $value ? 'bg-indigo-50 text-indigo-700' : 'text-gray-600 hover:bg-gray-100' }}">
                {{ $label }}
            </a>
        @endforeach
        <span class="ml-auto text-sm text-gray-500">{{ $runs->total() }} runs</span>
    </div>

    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Started</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">What</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Trigger</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Duration</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Result</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($runs as $run)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $run->started_at->format('d.m.Y H:i:s') }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="text-gray-900">{{ $kinds[$run->kind] ?? $run->kind }}</span>
                            <span class="text-gray-400"> · </span>
                            <span class="text-gray-600">{{ $run->subjectName() }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full {{ $triggerStyles[$run->triggered_by] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ $run->triggered_by }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if($run->status === \App\Models\SyncRun::STATUS_SUCCESS)
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full">success</span>
                            @elseif($run->status === \App\Models\SyncRun::STATUS_FAILED)
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700 rounded-full">failed</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded-full">running</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $run->durationLabel() ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            @if($run->message)
                                <span class="text-red-600">{{ $run->message }}</span>
                            @elseif($run->stats)
                                <span class="font-mono text-xs">
                                    @foreach($run->stats as $name => $value)
                                        @if($value !== null && $value !== 0)
                                            <span class="mr-2">{{ \Illuminate\Support\Str::snake($name, ' ') }}&nbsp;<span class="text-gray-900">{{ $value }}</span></span>
                                        @endif
                                    @endforeach
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">No sync runs recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($runs->hasPages())
        <div class="mt-4">{{ $runs->links() }}</div>
    @endif
</x-app-layout>
