@extends('layouts.app')
@section('title', 'Anmelden')
@section('content')
<div class="panel mx-auto mt-8 max-w-md p-8"><h1 class="!text-3xl">Anmelden</h1><p class="muted">Mit einem Administratorkonto anmelden.</p>
<form class="mt-8 space-y-5" method="POST" action="{{ route('login.store') }}">@csrf<label>E-Mail-Adresse<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" autofocus></label><label>Passwort<input type="password" name="password" required autocomplete="current-password"></label><button class="btn w-full">Anmelden <span aria-hidden="true">→</span></button></form>
<p class="mt-6 text-xs leading-relaxed text-stone-500">Eltern benötigen kein Konto. Bitte verwenden Sie den persönlichen Link aus Ihrer E-Mail.</p></div>
@endsection
