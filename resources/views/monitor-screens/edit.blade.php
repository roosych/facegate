<x-app-layout>
    @section('subtitle', 'Edit monitor screen')
    @section('title', $monitorScreen->name)

    <div class="max-w-3xl space-y-5">
        <div class="bg-indigo-50 border border-indigo-100 rounded-lg px-4 py-3 flex items-center justify-between gap-3">
            <div class="text-sm text-indigo-700 min-w-0">
                <span class="font-medium">Постоянная ссылка на этот экран</span>
                <span class="block text-xs text-indigo-500 mt-0.5">Не меняется, даже если поменять состав точек ниже — можно один раз открыть на киоске.</span>
            </div>
            <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('monitor.show-screen', ['monitorScreen' => $monitorScreen]) }}" target="_blank"
                class="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md transition-colors">
                Открыть монитор
            </a>
        </div>

        <form method="POST" action="{{ route('monitor-screens.update', $monitorScreen) }}" class="space-y-5">
            @csrf
            @method('PATCH')
            @include('monitor-screens._form')

            <div class="flex items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <button type="submit" class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                        Сохранить
                    </button>
                    <a href="{{ route('monitor-screens.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Отмена</a>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('monitor-screens.destroy', $monitorScreen) }}" onsubmit="return confirm('Удалить этот экран монитора? Ссылка перестанет работать.');">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-xs text-red-500 hover:text-red-700">Удалить экран</button>
        </form>
    </div>
</x-app-layout>
