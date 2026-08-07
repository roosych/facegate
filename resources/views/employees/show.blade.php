<x-app-layout>
    @section('subtitle', 'Employee profile')
    @section('title', $employee->full_name)

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- Profile card --}}
        <div class="bg-white rounded-lg shadow border border-gray-200 p-5">
            <div class="flex items-center gap-4 mb-4">
                @if($employee->photo_path)
                    <img src="{{ route('employees.photo', $employee) }}" alt="" class="w-16 h-16 rounded-xl object-cover">
                @else
                    <div class="w-16 h-16 rounded-xl bg-gray-200 flex items-center justify-center text-2xl font-bold text-gray-400">
                        {{ mb_substr($employee->first_name, 0, 1) }}
                    </div>
                @endif
                <div>
                    <h2 class="font-semibold text-gray-800">{{ $employee->full_name }}</h2>
                    <p class="text-sm text-gray-400 font-mono">{{ $employee->emp_code }}</p>
                </div>
            </div>
            <dl class="text-sm divide-y divide-gray-50">
                <div class="py-2"><dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">RusGuard UUID</dt><dd class="text-gray-700 font-mono text-xs mt-0.5 break-all">{{ $employee->rusguard_uuid }}</dd></div>
                <div class="py-2 flex justify-between gap-4"><dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Card No</dt><dd class="text-gray-700 font-mono text-right">{{ $employee->card_no ?? '—' }}</dd></div>
                <div class="py-2 flex justify-between gap-4"><dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Last Sync</dt><dd class="text-gray-700 text-right">{{ $employee->last_synced_at?->diffForHumans() ?? 'Never' }}</dd></div>
                <div class="py-2"><dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Access Points</dt><dd class="text-gray-700 mt-0.5">{{ $employee->accessPoints->pluck('name')->join(', ') ?: '—' }}</dd></div>
            </dl>
        </div>

        {{-- Recent Events --}}
        <div class="bg-white rounded-lg shadow border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Recent Events</h2>
            <div class="space-y-1 max-h-80 overflow-y-auto">
                @forelse($recentEvents as $event)
                    <div class="py-1.5 text-sm border-b border-gray-50 last:border-0">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">{{ $event->verify_type }}</span>
                            <span class="text-xs text-gray-400">{{ $event->event_time->format('d.m.y H:i') }}</span>
                        </div>
                        <div class="text-xs text-gray-400">{{ $event->device?->name ?? 'Unknown device' }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No events.</p>
                @endforelse
            </div>
        </div>

        {{-- Sync Logs --}}
        <div class="bg-white rounded-lg shadow border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Sync Logs</h2>
            <div class="space-y-1 max-h-80 overflow-y-auto">
                @forelse($employee->syncLogs as $log)
                    <div class="py-1.5 text-sm border-b border-gray-50 last:border-0">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-600">{{ str_replace('_', ' ', $log->action) }}</span>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-gray-400">{{ $log->created_at->format('d.m.y H:i') }}</span>
                                @if($log->status === 'success')
                                    <span class="text-xs font-medium text-green-600">ok</span>
                                @else
                                    <span class="text-xs font-medium text-red-600">err</span>
                                @endif
                            </div>
                        </div>
                        @if($log->message)
                            <div class="text-xs text-gray-400 truncate">{{ $log->message }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No sync history.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
