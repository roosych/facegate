<x-app-layout>
    @section('subtitle', 'Sync employees to Hikvision terminals')
    @section('title', 'Hikvision Sync')

    <div class="flex items-center justify-between bg-white rounded-lg border border-gray-200 px-5 py-4 mb-5">
        <p class="text-sm text-gray-500">Push employees from local DB to Hikvision terminals</p>
        <a href="{{ route('hikvision.index') }}" class="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
            Manage Terminals
        </a>
    </div>

    <div class="space-y-3">
        @forelse($terminals as $terminal)
            <div
                x-data="{
                    status: null,
                    poll(showFinished = false) {
                        fetch('{{ route('hikvision.sync.status', $terminal) }}')
                            .then(r => r.json())
                            .then(d => {
                                if (d.status === 'running' || d.status === 'queued') {
                                    this.status = d;
                                    setTimeout(() => this.poll(true), 2000);
                                } else if (showFinished && (d.status === 'done' || d.status === 'failed')) {
                                    this.status = d;
                                }
                            });
                    }
                }"
                x-init="poll()"
                class="bg-white rounded-lg border border-gray-200 p-5"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-sm font-medium text-gray-800">{{ $terminal->name }}</span>
                            <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-purple-50 text-purple-600 rounded-full">Hikvision</span>
                            @if($terminal->location)
                                <span class="text-xs text-gray-400">{{ $terminal->location }}</span>
                            @endif
                        </div>

                        <p class="text-xs text-gray-500 font-mono mb-2">{{ $terminal->protocol }}://{{ $terminal->ip }}:{{ $terminal->port }}</p>

                        @if($terminal->accessPoint)
                            <div class="mb-3">
                                <span class="inline-flex px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded">{{ $terminal->accessPoint->name }}</span>
                            </div>
                        @else
                            <p class="text-xs text-amber-600 mb-3">No access point linked — assign one in terminal settings.</p>
                        @endif

                        {{-- Sync status --}}
                        <div x-show="status !== null">
                            <div x-show="status?.status === 'running' || status?.status === 'queued'" class="flex items-center gap-2 text-xs text-indigo-600">
                                <svg class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span x-text="status?.status === 'queued' ? 'Queued...' : 'Syncing ' + (status?.done ?? 0) + '/' + (status?.total ?? '?') + ' · ' + (status?.synced ?? 0) + ' synced'"></span>
                            </div>

                            <div x-show="status?.status === 'done'" class="text-xs text-green-600">
                                Done — <span x-text="status?.synced ?? 0"></span> synced,
                                <span x-text="status?.removed ?? 0"></span> removed,
                                <span x-text="status?.errors ?? 0"></span> errors
                            </div>

                            <div x-show="status?.status === 'failed'" class="text-xs text-red-600">
                                Failed: <span x-text="status?.message"></span>
                            </div>
                        </div>
                    </div>

                    <div class="flex-shrink-0">
                        <button
                            type="button"
                            :disabled="status?.status === 'running' || status?.status === 'queued'"
                            class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            @click="
                                status = { status: 'queued' };
                                fetch('{{ route('hikvision.sync.terminal', $terminal) }}', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
                                }).then(() => poll(true));
                            "
                        >
                            Sync
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg border border-gray-200 px-4 py-12 text-center">
                <p class="text-sm text-gray-500 mb-4">No active Hikvision terminals.</p>
                <a href="{{ route('hikvision.create') }}" style="background-color:#4f46e5;color:#fff;padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:600;border-radius:0.5rem;text-decoration:none;display:inline-block">
                    Add Terminal
                </a>
            </div>
        @endforelse
    </div>
</x-app-layout>
