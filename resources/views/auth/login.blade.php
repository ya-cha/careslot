@extends('layouts.app')
@section('title', 'Anmelden')
@section('content')
<div class="mx-auto max-w-md">
    <div class="mb-8 text-center">
        <p class="eyebrow">Willkommen zurück</p>
        <h1>Anmelden</h1>
        <p class="muted">Mit Ihrem Administratorkonto bei careslot anmelden.</p>
    </div>
    <x-card bordered shadowless>
        <form class="space-y-5 p-2 sm:p-4" method="POST" action="{{ route('login.store') }}">
            @csrf
            <x-input label="E-Mail-Adresse" type="email" name="email" :value="old('email')" icon="envelope" required autocomplete="username" autofocus />
            <x-password label="Passwort" name="password" required autocomplete="current-password" />
            <x-button submit text="Anmelden" icon="arrow-right" position="right" block />
        </form>
    </x-card>
    <div class="mt-6 flex items-start gap-3 px-2 text-xs leading-relaxed text-gray-500">
        <x-icon icon="information-circle" class="size-5 shrink-0 text-gray-400" />
        <p>Eltern benötigen kein Konto. Bitte verwenden Sie den persönlichen Link aus Ihrer E-Mail.</p>
    </div>
</div>
@endsection
