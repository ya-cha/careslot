@extends('layouts.app')
@section('title', 'Nutzerkonto')
@section('content')
<x-card bordered shadowless header="Nutzerkonto">
    <p>Sie nutzen die Berechtigungen dieses Kontos.</p>
    @if(auth()->user()->is_admin)
        <x-button :href="route('dashboard')" text="Zur Übersicht" class="mt-5" />
    @else
        <p class="mt-3 text-sm text-gray-500">Dieses Konto hat keinen Zugriff auf die Verwaltung.</p>
    @endif
</x-card>
@endsection
