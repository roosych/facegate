<x-app-layout>
    @section('subtitle', 'Manage ZKT terminals')
    @section('title', 'Devices')

    <div class="flex items-center justify-between bg-white rounded-lg border border-gray-200 px-5 py-4 mb-5">
        <p class="text-sm text-gray-500">{{ $devices->total() }} devices</p>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('devices.sync-from-zkbio') }}">
                @csrf
                <button type="submit" style="background-color:#4f46e5;color:#fff;padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:600;border-radius:0.5rem;border:none;cursor:pointer;transition:background-color 0.15s">
                    Sync from ZKBio
                </button>
            </form>
            <a href="{{ route('devices.create') }}" class="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                Add Device
            </a>
        </div>
    </div>

    <div class="space-y-2">
        @forelse($devices as $device)
            <div
                x-data="{ open: false }"
                class="bg-white rounded-lg border border-gray-200 overflow-hidden"
            >
                <div class="flex items-center px-5 py-4 gap-3">

                    {{-- Main info --}}
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div class="min-w-0">
                            <span class="text-sm font-medium text-gray-800 truncate block">{{ $device->name }}</span>
                            @if($device->terminal_name && $device->terminal_name !== $device->name)
                                <span class="text-xs text-gray-400 truncate block">{{ $device->terminal_name }}</span>
                            @endif
                        </div>

                        @if($device->area_name)
                            <span class="inline-flex flex-shrink-0 px-2 py-0.5 text-xs font-medium bg-indigo-50 text-indigo-600 rounded-full">
                                {{ $device->area_name }}
                            </span>
                        @endif

                        @if($device->ip)
                            <span class="hidden sm:inline text-xs text-gray-500 font-mono flex-shrink-0 bg-gray-100 px-2 py-0.5 rounded">{{ $device->ip }}</span>
                        @endif

                        @if($device->user_count > 0 || $device->face_count > 0)
                            <span class="hidden md:inline-flex flex-shrink-0 px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">
                                {{ $device->user_count }} users · {{ $device->face_count }} faces
                            </span>
                        @endif

                        @if($device->last_activity)
                            <span class="hidden lg:inline text-xs text-gray-400 flex-shrink-0">{{ $device->last_activity->diffForHumans() }}</span>
                        @endif
                    </div>

                    {{-- SN --}}
                    <span class="hidden sm:inline text-xs text-gray-400 font-mono flex-shrink-0">{{ $device->sn }}</span>

                    {{-- Status dot --}}
                    <span class="flex-shrink-0 w-2 h-2 rounded-full {{ $device->is_active ? 'bg-green-400' : 'bg-gray-300' }}"></span>

                    {{-- Push button --}}
                    <button
                        type="button"
                        @click="open = true"
                        class="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-md transition-colors"
                    >
                        Push employees
                    </button>
                </div>

                {{-- Modal overlay --}}
                <div
                    x-show="open"
                    x-transition.opacity
                    class="fixed inset-0 flex items-center justify-center z-50"
                    style="background:rgba(0,0,0,0.4)"
                    @click.self="open = false"
                >
                    <div
                        x-show="open"
                        x-transition
                        style="background:#fff;border-radius:0.75rem;padding:1.5rem;width:100%;max-width:28rem;box-shadow:0 20px 60px rgba(0,0,0,0.15)"
                        @click.stop
                    >
                        <h3 style="font-size:1rem;font-weight:600;color:#111827;margin:0 0 0.25rem">Push employees to {{ $device->name }}</h3>
                        <p style="font-size:0.8125rem;color:#6b7280;margin:0 0 1.25rem">Select an access point — all synced employees will be pushed to this terminal.</p>

                        <form method="POST" action="{{ route('devices.push-from-access-point', $device) }}">
                            @csrf
                            <div style="margin-bottom:1rem">
                                <label style="display:block;font-size:0.8125rem;font-weight:500;color:#374151;margin-bottom:0.375rem">Access Point</label>
                                <select
                                    name="access_point_id"
                                    required
                                    style="width:100%;padding:0.5rem 0.75rem;border:1px solid #d1d5db;border-radius:0.5rem;font-size:0.875rem;color:#111827;background:#fff;outline:none"
                                >
                                    <option value="">— Select access point —</option>
                                    @foreach($turnstiles as $turnstile)
                                        <option value="{{ $turnstile->id }}">{{ $turnstile->rusguard_access_point_name ?: $turnstile->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div style="display:flex;gap:0.625rem;justify-content:flex-end">
                                <button
                                    type="button"
                                    @click="open = false"
                                    style="padding:0.5rem 1rem;font-size:0.875rem;font-weight:500;color:#6b7280;background:#f3f4f6;border:none;border-radius:0.5rem;cursor:pointer"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    style="padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:600;color:#fff;background:#4f46e5;border:none;border-radius:0.5rem;cursor:pointer"
                                >
                                    Push
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        @empty
            <div class="bg-white rounded-lg border border-gray-200 px-4 py-12 text-center">
                <p class="text-sm text-gray-500 mb-4">No devices configured yet. Press <strong>Sync from ZKBio</strong> to import terminals.</p>
                <form method="POST" action="{{ route('devices.sync-from-zkbio') }}">
                    @csrf
                    <button type="submit" style="background-color:#4f46e5;color:#fff;padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:600;border-radius:0.5rem;border:none;cursor:pointer">
                        Sync from ZKBio
                    </button>
                </form>
            </div>
        @endforelse
    </div>

    @if($devices->hasPages())
        <div class="mt-4">{{ $devices->links() }}</div>
    @endif
</x-app-layout>
