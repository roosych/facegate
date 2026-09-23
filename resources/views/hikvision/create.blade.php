<x-app-layout>
    @section('subtitle', 'Добавление нового терминала Hikvision')
    @section('title', 'Добавить терминал Hikvision')

    <div class="max-w-xl">
        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
            <form method="POST" action="{{ route('hikvision.store') }}" class="space-y-4">
                @csrf

                <div>
                    <x-input-label for="name" value="Название" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" placeholder="например, Терминал входа 1" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <x-input-label for="ip" value="IP-адрес" />
                        <x-text-input id="ip" name="ip" type="text" class="mt-1 block w-full font-mono" :value="old('ip')" placeholder="192.168.1.100" required />
                        <x-input-error :messages="$errors->get('ip')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="port" value="Порт" />
                        <x-text-input id="port" name="port" type="number" class="mt-1 block w-full" :value="old('port', 80)" required />
                        <x-input-error :messages="$errors->get('port')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-input-label for="protocol" value="Протокол" />
                    <select id="protocol" name="protocol" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="http" @selected(old('protocol', 'http') === 'http')>HTTP</option>
                        <option value="https" @selected(old('protocol') === 'https')>HTTPS</option>
                    </select>
                    <x-input-error :messages="$errors->get('protocol')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="username" value="Имя пользователя" />
                    <x-text-input id="username" name="username" type="text" class="mt-1 block w-full" :value="old('username', 'admin')" required />
                    <x-input-error :messages="$errors->get('username')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="password" value="Пароль" />
                    <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required />
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="location" value="Местоположение" />
                    <x-text-input id="location" name="location" type="text" class="mt-1 block w-full" :value="old('location')" placeholder="Главный вход" />
                    <x-input-error :messages="$errors->get('location')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="direction" value="Направление" />
                    <select id="direction" name="direction" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">— Не задано —</option>
                        <option value="in" @selected(old('direction') === 'in')>Вход</option>
                        <option value="out" @selected(old('direction') === 'out')>Выход</option>
                    </select>
                    <x-input-error :messages="$errors->get('direction')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="access_point_id" value="Точка доступа" />
                    <select id="access_point_id" name="access_point_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">— Не задано —</option>
                        @foreach($accessPoints as $accessPoint)
                            <option value="{{ $accessPoint->id }}" @selected(old('access_point_id') == $accessPoint->id)>
                                {{ $accessPoint->rusguard_access_point_name ?: $accessPoint->name }}
                            </option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('access_point_id')" class="mt-1" />
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <x-primary-button>Создать терминал</x-primary-button>
                    <a href="{{ route('hikvision.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Отмена</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
