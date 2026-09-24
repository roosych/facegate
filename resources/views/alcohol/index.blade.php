<x-app-layout>
    @section('title', 'Статус алкотестов')
    @section('subtitle', 'Кто обязан проходить проверку, на каких терминалах и когда последний раз проходил успешно')

    @if($missingCount > 0)
        <div class="mb-4 px-4 py-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg">
            Сотрудников из группы алкотеста RusGuard без соответствующей локальной записи: {{ $missingCount }} — запустите синхронизацию, чтобы их загрузить.
        </div>
    @endif

    <div class="bg-white rounded-lg border border-gray-200 mb-4" x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
        <button
            type="button"
            @click="open = !open"
            class="w-full flex items-center justify-between px-5 py-3 text-sm font-medium text-gray-700"
        >
            Настройки алкотеста
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
        </button>
        <div x-show="open" class="px-5 pb-4 divide-y divide-gray-100">
            <div class="pb-4">
                <form method="POST" action="{{ route('alcohol.grace-period') }}" class="flex items-end gap-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Льготный период после прохождения (минуты)</label>
                        <input
                            type="number"
                            name="grace_minutes"
                            value="{{ old('grace_minutes', $graceMinutes) }}"
                            min="1"
                            class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-32 focus:outline-none focus:ring-2 focus:ring-indigo-400"
                        >
                    </div>
                    <button type="submit" class="px-4 py-1.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors">
                        Сохранить
                    </button>
                    <p class="text-xs text-gray-400 mb-1.5">
                        Применяется ко всем — период AlcoGroup в самом RusGuard это отдельное понятие цикла проверки, а не этот льготный период.
                    </p>
                </form>
                @error('grace_minutes')
                    <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
                @enderror
            </div>

            <div class="py-4">
                <form method="POST" action="{{ route('alcohol.notifications') }}" class="flex items-end gap-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1">Порог уведомления (мг/100мл)</label>
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
                        <label class="block text-xs font-medium text-gray-500 mb-1">Email для уведомлений (через запятую)</label>
                        <input
                            type="text"
                            name="notification_emails"
                            value="{{ old('notification_emails', $notificationEmails) }}"
                            placeholder="security@example.com, hr@example.com"
                            class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-indigo-400"
                        >
                    </div>
                    <button type="submit" class="px-4 py-1.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors">
                        Сохранить
                    </button>
                </form>
                <p class="text-xs text-gray-400 mt-2">
                    При провале теста с концентрацией не ниже этой всем указанным адресатам отправляется письмо.
                </p>
                @error('notification_threshold')
                    <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
                @enderror
                @error('notification_emails')
                    <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4">
                <form method="POST" action="{{ route('alcohol.cleaning-notifications') }}" class="flex items-end gap-3">
                    @csrf
                    <div class="flex-1">
                        <label class="block text-xs font-medium text-gray-500 mb-1">Email техперсонала для уведомлений об очистке/калибровке терминала (через запятую)</label>
                        <input
                            type="text"
                            name="cleaning_notification_emails"
                            value="{{ old('cleaning_notification_emails', $cleaningNotificationEmails) }}"
                            placeholder="it@example.com"
                            class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-full focus:outline-none focus:ring-2 focus:ring-indigo-400"
                        >
                    </div>
                    <button type="submit" class="px-4 py-1.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors">
                        Сохранить
                    </button>
                </form>
                <p class="text-xs text-gray-400 mt-2">
                    Отдельный список от уведомлений о провале теста выше — это письмо про обслуживание устройства, не про сотрудника.
                </p>
                @error('cleaning_notification_emails')
                    <p class="text-xs text-red-500 mt-2">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    <div class="flex items-center justify-between mb-3">
        <p class="text-sm text-gray-500">Обязаны проходить проверку: {{ $rows->count() }}</p>
    </div>

    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Сотрудник</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Терминалы</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Последнее прохождение</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Текущий статус</th>
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
                                отладка
                            </a>
                        </td>

                        <td class="px-4 py-3 text-sm">
                            @forelse($row['terminals'] as $terminal)
                                <span class="inline-flex items-center px-1.5 py-0.5 text-xs bg-gray-100 text-gray-600 rounded mr-1">{{ $terminal->name }}</span>
                            @empty
                                <span class="text-gray-400">терминал не привязан</span>
                            @endforelse
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap">
                            @if($lastPass)
                                {{ $lastPass->event_time->format('d.m.Y H:i:s') }}
                                <span class="text-gray-400">— {{ $lastPass->hikvisionTerminal?->name ?? '—' }}</span>
                            @else
                                <span class="text-gray-400">никогда</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-sm">
                            <div class="flex items-center gap-2">
                                @if($skipActive)
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-green-50 text-green-700 border border-green-200 rounded-full">
                                        пройдено — до {{ $employee->alcohol_skip_until->format('d.m.Y H:i') }}
                                    </span>
                                    <form method="POST" action="{{ route('alcohol.clear-skip', $employee) }}">
                                        @csrf
                                        <button type="submit" class="text-xs text-gray-500 hover:text-red-600 underline">
                                            сбросить
                                        </button>
                                    </form>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-red-50 text-red-700 border border-red-200 rounded-full">
                                        требуется проверка
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-sm text-gray-400">
                            Сейчас нет сотрудников, которым требуется проверка на алкоголь.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
