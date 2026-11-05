@extends('layouts.app')
@section('title', 'Warteliste')
@section('content')
<div class="page-heading"><div><h1>Warteliste</h1><p class="muted">Reihenfolge der Kinder je Gruppe und Kita-Jahr.</p></div><a class="btn-secondary" href="{{ route('history') }}">Historie ansehen ↗</a></div>
<livewire:waiting-list />
@endsection
