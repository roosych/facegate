<x-app-layout>
    @section('subtitle', 'Edit access point settings')
    @section('title', 'Edit Access Point')

    <div class="max-w-xl">
        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
            <form method="POST" action="{{ route('access-points.update', $turnstile) }}" class="space-y-4"
                x-data="{
                    accessPoints: @js($accessPoints),
                    selectedId: '{{ old('rusguard_access_point_id', $turnstile->rusguard_access_point_id) }}',
                    get selectedName() {
                        return this.accessPoints.find(p => p.driverId === this.selectedId)?.name ?? '';
                    }
                }"
            >
                @csrf
                @method('PATCH')

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $turnstile->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="rusguard_access_point_id" value="RusGuard Access Point" />
                    <select
                        id="rusguard_access_point_id"
                        x-model="selectedId"
                        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                        required
                    >
                        <option value="">— Select access point —</option>
                        @foreach($accessPoints as $point)
                            <option value="{{ $point['driverId'] }}" @selected(old('rusguard_access_point_id', $turnstile->rusguard_access_point_id) === $point['driverId'])>
                                {{ $point['name'] }}
                            </option>
                        @endforeach
                    </select>
                    <input type="hidden" name="rusguard_access_point_id" :value="selectedId" />
                    <input type="hidden" name="rusguard_access_point_name" :value="selectedName" />
                    <x-input-error :messages="$errors->get('rusguard_access_point_id')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="enter_device_id" value="Entry Device" />
                    <select id="enter_device_id" name="enter_device_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">— None —</option>
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}" @selected(old('enter_device_id', $turnstile->enter_device_id) == $device->id)>{{ $device->name }} ({{ $device->sn }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('enter_device_id')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="exit_device_id" value="Exit Device" />
                    <select id="exit_device_id" name="exit_device_id" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm">
                        <option value="">— None —</option>
                        @foreach($devices as $device)
                            <option value="{{ $device->id }}" @selected(old('exit_device_id', $turnstile->exit_device_id) == $device->id)>{{ $device->name }} ({{ $device->sn }})</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('exit_device_id')" class="mt-1" />
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <x-primary-button>Save Changes</x-primary-button>
                    <a href="{{ route('access-points.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
