<x-app-layout>
    @section('subtitle', 'Per-access-point resync from RusGuard')
    @section('title', 'Sync')

    <div class="mb-5">
        <form method="POST" action="{{ route('sync.all') }}">
            @csrf
            <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                Sync All Access Points
            </button>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($accessPoints as $accessPoint)
            <div class="bg-white rounded-lg shadow border border-gray-200 p-5">
                <div class="flex items-start justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-gray-800 text-sm">{{ $accessPoint->name }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $accessPoint->rusguard_access_point_name }}</p>
                    </div>
                    @if($accessPoint->is_active)
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full">Active</span>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">Inactive</span>
                    @endif
                </div>

                <div class="flex gap-2">
                    <form method="POST" action="{{ route('sync.access-point', $accessPoint) }}" class="w-full">
                        @csrf
                        <button type="submit" class="w-full px-4 py-2 border border-indigo-200 text-indigo-600 text-sm rounded-lg hover:bg-indigo-50 transition-colors">
                            Sync from RusGuard
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="col-span-3 py-8 text-center text-sm text-gray-400">No active access points. <a href="{{ route('access-points.create') }}" class="text-indigo-600">Add one.</a></div>
        @endforelse
    </div>
</x-app-layout>
