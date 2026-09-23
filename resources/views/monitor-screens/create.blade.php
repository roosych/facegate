<x-app-layout>
    @section('subtitle', 'Create a new monitor screen')
    @section('title', 'New Monitor Screen')

    <div class="max-w-3xl">
        <form method="POST" action="{{ route('monitor-screens.store') }}" class="space-y-5">
            @csrf
            @include('monitor-screens._form', ['monitorScreen' => null])

            <div class="flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    Создать экран
                </button>
                <a href="{{ route('monitor-screens.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Отмена</a>
            </div>
        </form>
    </div>
</x-app-layout>
