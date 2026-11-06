<x-app-layout :title="__('Profile')" games="any">
    @include('profile.partials.update-profile-information-form')
    @include('profile.partials.update-password-form')
    @include('profile.partials.delete-user-form')
</x-app-layout>
