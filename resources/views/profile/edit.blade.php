<x-app-layout>
    @section('subtitle', 'Your account settings')
    @section('title', 'Profile')

    <div class="space-y-5 max-w-2xl">
        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
            @include('profile.partials.update-password-form')
        </div>

        <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-app-layout>
