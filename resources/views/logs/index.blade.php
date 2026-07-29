<x-app-layout>
    @section('subtitle', 'RusGuard sync history')
    @section('title', 'Sync Logs')

    <div class="flex items-center justify-between mb-5">
        <p class="text-sm text-gray-500">{{ $logs->total() }} records</p>
    </div>

    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Time</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Employee</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Device</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Message</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($logs as $log)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                        <td class="px-4 py-3 text-sm">
                            @if($log->employee)
                                <a href="{{ route('employees.show', $log->employee) }}" class="text-indigo-600 hover:text-indigo-800">
                                    {{ $log->employee->full_name }}
                                </a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $log->device?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="font-mono text-xs text-gray-700">{{ str_replace('_', ' ', $log->action) }}</span>
                        </td>
                        <td class="px-4 py-3">
                            @if($log->status === 'success')
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full">success</span>
                            @elseif($log->status === 'error')
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700 rounded-full">error</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">{{ $log->status }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500 max-w-xs truncate">{{ $log->message ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">No sync logs yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
        <div class="mt-4">{{ $logs->links() }}</div>
    @endif
</x-app-layout>
