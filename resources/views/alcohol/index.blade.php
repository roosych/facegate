<x-app-layout>
    @section('title', 'Alcohol Status')
    @section('subtitle', 'Who must test, on which terminals, and when they last passed')

    @if($missingCount > 0)
        <div class="mb-4 px-4 py-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg">
            {{ $missingCount }} employee(s) required by RusGuard's alcohol group have no matching local record yet — run a sync to pull them in.
        </div>
    @endif

    <div class="bg-white rounded-lg border border-gray-200 px-5 py-4 mb-4">
        <form method="POST" action="{{ route('alcohol.grace-period') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Grace period after passing (minutes)</label>
                <input
                    type="number"
                    name="grace_minutes"
                    value="{{ old('grace_minutes', $graceMinutes) }}"
                    min="1"
                    class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-32 focus:outline-none focus:ring-2 focus:ring-indigo-400"
                >
            </div>
            <button type="submit" class="px-4 py-1.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors">
                Save
            </button>
            <p class="text-xs text-gray-400 mb-1.5">
                Applies to everyone — RusGuard's own AlcoGroup period setting is a separate compliance-cycle concept, not this grace window.
            </p>
        </form>
        @error('grace_minutes')
            <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
        @enderror
    </div>

    <div class="bg-white rounded-lg border border-gray-200 px-5 py-4 mb-4">
        <form method="POST" action="{{ route('alcohol.notifications') }}" class="flex items-end gap-3">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Notify threshold (mg/100ml)</label>
                <input
                    type="number"
                    step="0.01"
                    name="notification_threshold"
                    value="{{ old('notification_threshold', $notificationThreshold) }}"
                    min="0"
                    class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-32 focus:outline-none focus:ring-2 focus:ring-indigo-400"
                >
            </div>
            <div class="flex-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Notify emails (comma-separated)</label>
                <input
                    type="text"
                    name="notification_emails"
                    value="{{ old('notification_emails', $notificationEmails) }}"
                    placeholder="security@example.com, hr@example.com"
                    class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-indigo-400"
                >
            </div>
            <button type="submit" class="px-4 py-1.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors">
                Save
            </button>
        </form>
        <p class="text-xs text-gray-400 mt-2">
            A failed test at or above this concentration emails everyone listed.
        </p>
        @error('notification_threshold')
            <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
        @enderror
        @error('notification_emails')
            <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
        @enderror
    </div>

    <div class="flex items-center justify-between mb-3">
        <p class="text-sm text-gray-500">{{ $rows->count() }} employee(s) required to test</p>
    </div>

    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Employee</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Terminals</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Last passed</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status now</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $row)
                    @php
                        $employee = $row['employee'];
                        $lastPass = $row['lastPass'];
                        $skipActive = $employee->isAlcoholSkipActive();
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm">
                            <a href="{{ route('employees.show', $employee) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">
                                {{ $employee->full_name }}
                            </a>
                            <a href="{{ route('alcohol.debug', $employee) }}" class="ml-2 text-xs text-gray-400 hover:text-gray-600 underline">
                                debug
                            </a>
                        </td>

                        <td class="px-4 py-3 text-sm">
                            @forelse($row['terminals'] as $terminal)
                                <span class="inline-flex items-center px-1.5 py-0.5 text-xs bg-gray-100 text-gray-600 rounded mr-1">{{ $terminal->name }}</span>
                            @empty
                                <span class="text-gray-400">no linked terminal</span>
                            @endforelse
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                            @if($lastPass)
                                {{ $lastPass->event_time->format('d.m.Y H:i:s') }}
                                <span class="text-gray-400">— {{ $lastPass->hikvisionTerminal?->name ?? '—' }}</span>
                            @else
                                <span class="text-gray-400">never</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <div class="flex items-center gap-2">
                                @if($skipActive)
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-green-50 text-green-700 border border-green-200 rounded-full">
                                        passed — until {{ $employee->alcohol_skip_until->format('d.m.Y H:i') }}
                                    </span>
                                    <form method="POST" action="{{ route('alcohol.clear-skip', $employee) }}">
                                        @csrf
                                        <button type="submit" class="text-xs text-gray-500 hover:text-red-600 underline">
                                            clear
                                        </button>
                                    </form>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-red-50 text-red-700 border border-red-200 rounded-full">
                                        must test
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-sm text-gray-400">
                            No employees currently require alcohol testing.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
