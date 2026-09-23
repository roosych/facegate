<x-app-layout>
    @section('subtitle', 'Синхронизировано из RusGuard')
    @section('title', 'Сотрудники')

    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <p class="text-sm text-gray-500">{{ $employees->total() }} сотрудников</p>
            @if(! $showInactive && $inactiveCount > 0)
                <span class="text-xs text-gray-400">(неактивных скрыто: {{ $inactiveCount }})</span>
            @endif
        </div>
        <form method="GET" action="{{ route('employees.index') }}" class="flex items-center gap-3">
            <label class="flex items-center gap-1.5 text-sm text-gray-500 select-none">
                <input
                    type="checkbox"
                    name="show_inactive"
                    value="1"
                    onchange="this.form.submit()"
                    @checked($showInactive)
                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-400"
                >
                Показывать неактивных
            </label>
            <input
                type="search"
                name="search"
                value="{{ $search }}"
                placeholder="Имя, должность, отдел, карта…"
                class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-64 focus:outline-none focus:ring-2 focus:ring-indigo-400"
            >
            <button type="submit" class="text-sm bg-indigo-600 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-700">Найти</button>
            @if($search !== '')
                <a href="{{ route('employees.index', ['show_inactive' => $showInactive ? 1 : null]) }}" class="inline-flex items-center gap-1 text-sm text-gray-400 hover:text-gray-600 border border-gray-300 rounded-lg px-3 py-1.5 hover:border-gray-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                    </svg>
                    Сбросить
                </a>
            @endif
        </form>
    </div>

    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Фото</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Имя</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Должность</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Отдел</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Ключи</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Точки доступа</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Синхронизация</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Статус</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($employees as $employee)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            @if($employee->photo_path)
                                <img src="{{ route('employees.photo', $employee) }}" alt="" class="w-8 h-8 rounded-full object-cover">
                            @else
                                <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-xs text-gray-500 font-medium">
                                    {{ mb_substr($employee->first_name, 0, 1) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-800">
                            <a href="{{ route('employees.show', $employee) }}" class="hover:text-indigo-600">
                                {{ $employee->full_name }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $employee->position ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600">{{ $employee->department ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @forelse($employee->keys as $key)
                                <span class="inline-flex items-center px-1.5 py-0.5 text-xs font-mono bg-gray-100 text-gray-600 rounded">{{ $key->value }}</span>
                            @empty
                                <span class="text-sm text-gray-300">—</span>
                            @endforelse
                        </td>
                        <td class="px-4 py-3" x-data="{ open: false }">
                            @if($employee->accessPoints->count() > 0)
                                <button
                                    @click="open = true"
                                    class="inline-flex items-center justify-center w-6 h-6 text-xs font-semibold bg-indigo-50 text-indigo-700 rounded-full hover:bg-indigo-100 transition-colors"
                                >{{ $employee->accessPoints->count() }}</button>

                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition.opacity
                                    class="fixed inset-0 z-50 flex items-center justify-center"
                                    style="background:rgba(0,0,0,0.45)"
                                    @click.self="open = false"
                                >
                                    <div
                                        x-show="open"
                                        x-transition
                                        @click.stop
                                        class="bg-white rounded-xl shadow-2xl w-full max-w-2xl flex flex-col"
                                        style="margin:1rem;max-height:80vh;overflow:hidden"
                                    >
                                        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                                            <div>
                                                <h3 class="text-sm font-semibold text-gray-900">Точки доступа</h3>
                                                <p class="text-xs text-gray-400 mt-0.5">{{ $employee->full_name }}</p>
                                            </div>
                                            <button @click="open = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="overflow-y-auto flex-1 min-h-0 px-5 py-3">
                                            <div class="divide-y divide-gray-50">
                                                @foreach($employee->accessPoints as $accessPoint)
                                                    <div class="py-2.5 text-sm text-gray-700">{{ $accessPoint->name }}</div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <span class="text-sm text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            {{ $employee->last_synced_at?->diffForHumans() ?? 'Никогда' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($employee->is_active)
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700 rounded-full">Активен</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 rounded-full">Неактивен</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">
                            @if($showInactive || $inactiveCount === 0)
                                Сотрудники ещё не синхронизированы. Запустите синхронизацию, чтобы импортировать их из RusGuard.
                            @else
                                Нет совпадений среди активных сотрудников. Скрыто неактивных: {{ $inactiveCount }} — включите «Показывать неактивных», чтобы увидеть их.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($employees->hasPages())
        <div class="mt-4">{{ $employees->links() }}</div>
    @endif
</x-app-layout>
