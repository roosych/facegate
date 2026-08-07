<x-app-layout>
    @section('subtitle', 'Add a new ZKT terminal')
    @section('title', 'Add Device')

    <div class="max-w-xl">
        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
            <form method="POST" action="{{ route('devices.store') }}" class="space-y-4">
                @csrf

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" placeholder="e.g. AccessPoint 1 Entry" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="ip" value="IP Address" />
                    <x-text-input id="ip" name="ip" type="text" class="mt-1 block w-full font-mono" :value="old('ip')" placeholder="192.168.1.100" required />
                    <x-input-error :messages="$errors->get('ip')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="sn" value="Serial Number" />
                    <x-text-input id="sn" name="sn" type="text" class="mt-1 block w-full font-mono" :value="old('sn')" placeholder="VGU6254900197" required />
                    <x-input-error :messages="$errors->get('sn')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="zkbio_terminal_id" value="ZKBio Terminal ID" />
                    <x-text-input id="zkbio_terminal_id" name="zkbio_terminal_id" type="number" class="mt-1 block w-full" :value="old('zkbio_terminal_id')" />
                    <x-input-error :messages="$errors->get('zkbio_terminal_id')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="location" value="Location" />
                    <x-text-input id="location" name="location" type="text" class="mt-1 block w-full" :value="old('location')" placeholder="Main Entrance" />
                    <x-input-error :messages="$errors->get('location')" class="mt-1" />
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <x-primary-button>Create Device</x-primary-button>
                    <a href="{{ route('devices.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
