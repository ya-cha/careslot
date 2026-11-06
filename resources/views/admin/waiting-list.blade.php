@extends('layouts.app')
@section('title', 'Warteliste')
@section('content')
<div class="page-heading"><div><h1>Warteliste</h1><p class="muted">Reihenfolge der Kinder je Gruppe und Kita-Jahr.</p></div><x-button color="gray" outline href="{{ route('history') }}">Historie ansehen</x-button></div>
<livewire:waiting-list />
@endsection
