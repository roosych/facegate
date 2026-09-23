<x-app-layout>
    @section('subtitle', 'Именованные группы турникетов для физического монитора')
    @section('title', 'Экраны мониторинга')

    <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-gray-500">Каждый экран — постоянная ссылка на один или несколько турникетов для монитора на посту (ресепшн и т.д.).</p>
        <a href="{{ route('monitor-screens.create') }}" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
            + Новый экран
        </a>
    </div>

    <div class="space-y-2">
        @forelse($screens as $screen)
            <div class="bg-white rounded-lg border border-gray-200 px-5 py-4 flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <span class="text-sm font-semibold text-gray-800">{{ $screen->name }}</span>
                    <span class="block text-xs text-gray-400 mt-0.5">{{ $screen->access_points_count }} {{ $screen->access_points_count === 1 ? 'точка' : 'точек' }}</span>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('monitor.show-screen', ['monitorScreen' => $screen]) }}" target="_blank"
                        class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                        Открыть
                    </a>
                    <a href="{{ route('monitor-screens.edit', $screen) }}"
                        class="px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-md transition-colors">
                        Изменить
                    </a>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-lg border border-gray-200 px-4 py-12 text-center">
                <p class="text-sm text-gray-500 mb-4">Экранов ещё нет.</p>
                <a href="{{ route('monitor-screens.create') }}" class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    Создать первый экран
                </a>
            </div>
        @endforelse
    </div>

    @if($screens->hasPages())
        <div class="mt-4">{{ $screens->links() }}</div>
    @endif
</x-app-layout>
