@extends('layouts.app')
@section('title', 'Kita-Jahre')
@section('content')
<div class="page-heading">
    <div><h1>Kita-Jahre</h1><p class="muted">Grundlisten werden für jede Gruppe und jedes Kita-Jahr separat erstellt.</p></div>
</div>
<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
    <div class="space-y-5">
        @forelse($years as $year)
            <x-card bordered shadowless>
                <x-slot:header>
                    <div class="flex items-center gap-3"><span class="grid size-9 place-items-center rounded-lg bg-indigo-50 text-indigo-600"><x-icon icon="calendar" class="size-5" /></span><h2>{{ $year->name }}</h2></div>
                    <x-badge :color="$year->status === 'active' ? 'indigo' : 'gray'" light>{{ $year->status === 'active' ? 'Aktiv' : 'Archiviert' }}</x-badge>
                </x-slot:header>
                <p class="text-sm text-gray-500">{{ $year->starts_on->format('d.m.Y') }} – {{ $year->ends_on->format('d.m.Y') }} · {{ $year->children_count }} Kinder</p>
                <div class="mt-5 divide-y divide-gray-100">
                    @foreach($groups as $group)
                        @php($list = $lists->where('kita_year_id', $year->id)->firstWhere('group_id', $group->id))
                        <div class="flex flex-wrap items-center justify-between gap-3 py-4 last:pb-0">
                            <span class="text-sm font-medium">{{ $group->name }}</span>
                            @if(!$list?->waiting_list_initialized_at)
                                <form method="POST" action="{{ route('years.initialize', $year) }}">
                                    @csrf
                                    <input type="hidden" name="group_id" value="{{ $group->id }}">
                                    <x-button submit color="gray" outline sm text="Grundliste erstellen" icon="queue-list" />
                                </form>
                            @else
                                <span class="flex items-center gap-1.5 text-xs font-medium text-indigo-600"><x-icon icon="check-circle" class="size-4" />Grundliste erstellt</span>
                            @endif
                        </div>
                    @endforeach
                </div>
                @can('manage-groups')
                    @if($year->status === 'active')
                        <x-slot:footer>
                            <form method="POST" action="{{ route('years.archive', $year) }}">@csrf<x-button submit color="gray" flat sm text="Jahr archivieren" icon="archive-box" /></form>
                        </x-slot:footer>
                    @endif
                @endcan
            </x-card>
        @empty
            <x-card bordered shadowless>
                <div class="py-10 text-center"><x-icon icon="calendar" class="mx-auto mb-4 size-10 text-gray-300" /><h2>Noch kein Kita-Jahr vorhanden.</h2><p class="muted">Kita-Jahre bilden die Grundlage für Kinder und Wartelisten.</p></div>
            </x-card>
        @endforelse
    </div>
    @can('manage-groups')
        <x-card bordered shadowless header="Neues Kita-Jahr">
            <form class="space-y-5" method="POST" action="{{ route('years.store') }}">
                @csrf
                <x-input label="Bezeichnung" name="name" placeholder="2026/2027" :value="old('name')" required maxlength="100" />
                <x-input label="Beginn" type="date" name="starts_on" :value="old('starts_on')" required />
                <x-input label="Ende" type="date" name="ends_on" :value="old('ends_on')" required />
                <input type="hidden" name="status" value="active">
                <x-button submit block text="Kita-Jahr anlegen" icon="plus" />
            </form>
        </x-card>
    @endcan
</div>
@endsection
