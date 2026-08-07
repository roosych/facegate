<x-app-layout>
    @section('subtitle', 'Access point details')
    @section('title', $accessPoint->name)

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- Details --}}
        <div class="bg-white rounded-lg shadow border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Details</h2>
            <dl class="text-sm divide-y divide-gray-50">
                <div class="py-2"><dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Access Point</dt><dd class="text-gray-700 mt-0.5">{{ $accessPoint->rusguard_access_point_name }}</dd></div>
                <div class="py-2"><dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">UUID</dt><dd class="text-gray-700 font-mono text-xs mt-0.5 break-all">{{ $accessPoint->rusguard_access_point_id }}</dd></div>
                <div class="py-2 flex justify-between gap-4 items-center"><dt class="text-xs font-medium text-gray-400 uppercase tracking-wide">Status</dt><dd>
                    @if($accessPoint->is_active)
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full">Active</span>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-500 rounded-full">Inactive</span>
                    @endif
                </dd></div>
            </dl>
            <div class="mt-4">
                <form method="POST" action="{{ route('sync.access-point', $accessPoint) }}">
                    @csrf
                    <button type="submit" class="w-full px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 transition-colors">
                        Sync This Access Point
                    </button>
                </form>
            </div>
        </div>

        {{-- Employees --}}
        <div class="bg-white rounded-lg shadow border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Employees ({{ $accessPoint->employees->count() }})</h2>
            <div class="space-y-1 max-h-80 overflow-y-auto">
                @forelse($accessPoint->employees as $employee)
                    <a href="{{ route('employees.show', $employee) }}" class="flex items-center justify-between py-1.5 px-2 rounded hover:bg-gray-50 text-sm">
                        <span class="text-gray-800">{{ $employee->full_name }}</span>
                        <span class="text-xs text-gray-400 font-mono">{{ $employee->emp_code }}</span>
                    </a>
                @empty
                    <p class="text-sm text-gray-400">No employees synced yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Recent Events --}}
        <div class="bg-white rounded-lg shadow border border-gray-200 p-5">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Recent Events</h2>
            <div class="space-y-1 max-h-80 overflow-y-auto">
                @forelse($recentEvents as $event)
                    <div class="py-1.5 text-sm border-b border-gray-50 last:border-0">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-800 font-medium">{{ $event->employee?->full_name ?? 'Unknown' }}</span>
                            <span class="text-xs text-gray-400">{{ $event->event_time->format('d.m H:i') }}</span>
                        </div>
                        <div class="text-xs text-gray-400">{{ $event->verify_type }} &mdash; {{ $event->device?->name ?? '?' }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No events yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
