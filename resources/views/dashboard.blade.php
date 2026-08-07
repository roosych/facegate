<x-app-layout>
    @section('subtitle', 'System overview')
    @section('title', 'Dashboard')

    {{-- Stats row --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
        <div class="bg-white rounded-lg border border-gray-200 px-4 py-4">
            <p class="text-xs text-gray-400 font-medium">Employees</p>
            <p class="mt-1 text-2xl font-bold text-indigo-600">{{ $stats['employees'] }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ $stats['active_employees'] }} active</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 px-4 py-4">
            <p class="text-xs text-gray-400 font-medium">Devices</p>
            <p class="mt-1 text-2xl font-bold text-blue-600">{{ $stats['devices'] }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ $stats['accessPoints'] }} access points</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 px-4 py-4">
            <p class="text-xs text-gray-400 font-medium">Events Today</p>
            <p class="mt-1 text-2xl font-bold text-emerald-600">{{ $stats['events_today'] }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ $stats['events_week'] }} this week</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 px-4 py-4">
            <p class="text-xs text-gray-400 font-medium">Failed Syncs</p>
            <p class="mt-1 text-2xl font-bold {{ $stats['failed_syncs'] > 0 ? 'text-red-500' : 'text-gray-400' }}">{{ $stats['failed_syncs'] }}</p>
            <p class="text-xs text-gray-400 mt-0.5">today</p>
        </div>
        <div class="bg-white rounded-lg border border-gray-200 px-4 py-4">
            <p class="text-xs text-gray-400 font-medium">No Card</p>
            <p class="mt-1 text-2xl font-bold {{ $stats['no_card'] > 0 ? 'text-amber-500' : 'text-gray-400' }}">{{ $stats['no_card'] }}</p>
            <p class="text-xs text-gray-400 mt-0.5">employees</p>
        </div>
    </div>

    {{-- Quick Actions --}}
    <div class="bg-white rounded-lg border border-gray-200 px-5 py-4 mb-4">
        <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">Quick Actions</h2>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('sync.all') }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition-colors">
                    Sync All Access Points
                </button>
            </form>
            <a href="{{ route('employees.index') }}" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                Employees
            </a>
            <a href="{{ route('access-points.index') }}" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                Access Points
            </a>
            <a href="{{ route('devices.index') }}" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                Devices
            </a>
        </div>
    </div>

    {{-- Live Process Status --}}
    <div
        class="bg-white rounded-lg border border-gray-200 mb-4"
        x-data="{
            data: null,
            timer: null,
            get anyActive() {
                if (!this.data) return false;
                if (['pending', 'running'].includes(this.data.rusguard_sync?.status)) return true;
                return (this.data.terminals ?? []).some(t => ['pending', 'running'].includes(t.status));
            },
            async poll() {
                try {
                    const res = await fetch('{{ route('dashboard.status') }}');
                    this.data = await res.json();
                } catch (e) {
                    // network blip — retry
                }
                this.timer = setTimeout(() => this.poll(), this.anyActive ? 2000 : 15000);
            },
            init() {
                this.poll();
            }
        }"
    >
        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
            <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Live Process Status</h2>
            <span class="text-xs text-gray-400" x-show="data">
                Queue: <span x-text="data?.queue?.pending ?? 0"></span> pending
                <template x-if="data?.failed_last_24h > 0">
                    <span class="text-red-500 font-medium"> · <span x-text="data.failed_last_24h"></span> failed (24h)</span>
                </template>
            </span>
        </div>

        <div x-show="data">
            {{-- RusGuard org-wide sync --}}
            <div class="flex items-center gap-3 px-5 py-2.5 border-b border-gray-50">
                <span
                    class="flex-shrink-0 w-2 h-2 rounded-full"
                    :class="{
                        'bg-indigo-400 animate-pulse': ['pending', 'running'].includes(data?.rusguard_sync?.status),
                        'bg-emerald-400': data?.rusguard_sync?.status === 'done',
                        'bg-red-400': data?.rusguard_sync?.status === 'failed',
                        'bg-gray-300': !data?.rusguard_sync?.status || data?.rusguard_sync?.status === 'idle',
                    }"
                ></span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-800">RusGuard → Local Sync</p>
                    <p
                        class="text-xs text-gray-400"
                        x-text="
                            ['pending', 'running'].includes(data?.rusguard_sync?.status)
                                ? (data.rusguard_sync.current || 'Working...') + ' — ' + (data.rusguard_sync.emp_done ?? data.rusguard_sync.done ?? 0) + '/' + (data.rusguard_sync.emp_total ?? data.rusguard_sync.total ?? '?')
                                : (!data?.rusguard_sync?.status || data?.rusguard_sync?.status === 'idle' ? 'Idle' : data.rusguard_sync.status)
                        "
                    ></p>
                </div>
            </div>

            {{-- Hikvision terminals --}}
            <template x-for="t in (data?.terminals ?? [])" :key="t.id">
                <div class="flex items-center gap-3 px-5 py-2.5 border-b border-gray-50 last:border-b-0">
                    <span
                        class="flex-shrink-0 w-2 h-2 rounded-full"
                        :class="{
                            'bg-indigo-400 animate-pulse': ['pending', 'running'].includes(t.status),
                            'bg-emerald-400': ['done', 'idle'].includes(t.status),
                            'bg-red-400': t.status === 'failed',
                        }"
                    ></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-gray-800 truncate" x-text="t.name"></p>
                        <p class="text-xs text-gray-400 truncate">
                            <template x-if="['pending', 'running'].includes(t.status)">
                                <span x-text="'Syncing ' + (t.done ?? 0) + '/' + (t.total ?? '?')"></span>
                            </template>
                            <template x-if="!['pending', 'running'].includes(t.status)">
                                <span x-text="t.synced_at ? 'Last synced ' + t.synced_at : 'Never synced'"></span>
                            </template>
                        </p>
                    </div>
                    <div class="text-right flex-shrink-0 text-xs space-y-0.5">
                        <p x-show="t.persons_failed > 0" class="text-red-500" x-text="t.persons_failed + ' failed'"></p>
                        <p x-show="t.alcohol_enabled && t.alcohol_failed > 0" class="text-amber-500" x-text="t.alcohol_failed + ' alco failed'"></p>
                    </div>
                </div>
            </template>

            <p x-show="!data?.terminals || data.terminals.length === 0" class="text-sm text-gray-400 text-center py-6">No active terminals</p>

            {{-- Recent failures --}}
            <template x-if="data?.recent_failures?.length > 0">
                <div class="px-5 py-2.5 bg-red-50/50">
                    <p class="text-xs font-semibold text-red-500 uppercase tracking-wide mb-1.5">Recent Failed Jobs</p>
                    <template x-for="f in data.recent_failures" :key="f.failed_at + f.job">
                        <p class="text-xs text-gray-500">
                            <span x-text="f.job.replace('App\\\\Jobs\\\\', '')"></span>
                            <span class="text-gray-300">·</span>
                            <span x-text="f.failed_at"></span>
                        </p>
                    </template>
                </div>
            </template>
        </div>
    </div>

    {{-- Two column blocks --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- Recent Access Events --}}
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Recent Access Events</h2>
                <span class="text-xs text-gray-400">{{ $stats['events_today'] }} today</span>
            </div>
            @if($recentEvents->isEmpty())
                <p class="px-5 py-8 text-sm text-gray-400 text-center">No events yet</p>
            @else
                <ul class="divide-y divide-gray-50">
                    @foreach($recentEvents as $event)
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            {{-- Direction indicator --}}
                            <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full mt-0.5 {{ $event->direction === 'in' ? 'bg-emerald-400' : 'bg-gray-300' }}"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 truncate">
                                    {{ $event->employee?->full_name ?? 'Unknown' }}
                                </p>
                                <p class="text-xs text-gray-400 truncate">{{ $event->accessPoint?->name ?? '—' }}</p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-xs text-gray-500">{{ $event->event_time->format('H:i') }}</p>
                                <p class="text-xs text-gray-300">{{ $event->event_time->format('d.m') }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Recent Sync Activity --}}
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                <h2 class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Recent Sync Activity</h2>
                @if($stats['failed_syncs'] > 0)
                    <span class="text-xs font-medium text-red-500">{{ $stats['failed_syncs'] }} failed today</span>
                @else
                    <span class="text-xs text-gray-400">
                        {{ $stats['last_sync'] ? \Carbon\Carbon::parse($stats['last_sync'])->diffForHumans() : 'Never' }}
                    </span>
                @endif
            </div>
            @if($recentSyncs->isEmpty())
                <p class="px-5 py-8 text-sm text-gray-400 text-center">No sync activity yet</p>
            @else
                <ul class="divide-y divide-gray-50">
                    @foreach($recentSyncs as $sync)
                        <li class="flex items-center gap-3 px-5 py-2.5">
                            {{-- Status dot --}}
                            <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full mt-0.5 {{
                                match($sync->status) {
                                    'success' => 'bg-emerald-400',
                                    'error'   => 'bg-red-400',
                                    default   => 'bg-gray-300',
                                }
                            }}"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-gray-800 truncate">
                                    {{ $sync->employee?->full_name ?? $sync->device?->name ?? $sync->hikvisionTerminal?->name ?? '—' }}
                                </p>
                                <p class="text-xs text-gray-400 truncate">{{ $sync->action ?? '—' }}</p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-xs text-gray-500">{{ $sync->created_at->format('H:i') }}</p>
                                <p class="text-xs text-gray-300">{{ $sync->created_at->format('d.m') }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

    </div>
</x-app-layout>
