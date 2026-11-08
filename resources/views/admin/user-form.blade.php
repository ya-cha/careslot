@extends('layouts.app')
@section('title', 'Benutzer bearbeiten')
@section('content')
<div class="page-heading">
    <div><h1>Benutzer bearbeiten</h1><p class="muted">{{ $user->name }} · {{ $user->email }}</p></div>
    <x-button :href="route('groups.index')" color="gray" outline text="Zurück zu Gruppen & Admins" icon="arrow-left" />
</div>
<div class="max-w-2xl space-y-6">
    <x-card bordered shadowless header="Benutzerdaten">
        <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-5">
            @csrf @method('PUT')
            <x-input id="user-name" label="Name" name="name" :value="old('name', $user->name)" required maxlength="150" autocomplete="name" />
            <x-input id="user-email" label="E-Mail-Adresse" type="email" name="email" :value="old('email', $user->email)" required maxlength="255" autocomplete="email" />
            <x-button submit text="Benutzer speichern" />
        </form>
    </x-card>
    <section id="passwort" class="scroll-mt-6" aria-label="Passwort ändern">
        <x-card bordered shadowless header="Passwort ändern">
            <form method="POST" action="{{ route('users.password.update', $user) }}" class="space-y-5">
                @csrf @method('PUT')
                <x-password id="user-password" label="Neues Passwort" name="password" required minlength="12" autocomplete="new-password" />
                <x-password id="user-password-confirmation" label="Passwort wiederholen" name="password_confirmation" required minlength="12" autocomplete="new-password" />
                <x-button submit text="Passwort ändern" />
            </form>
        </x-card>
    </section>
</div>
@endsection
