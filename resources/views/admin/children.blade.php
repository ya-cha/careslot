@extends('layouts.app')
@section('title', 'Kinder & Familien')
@section('content')
<div class="page-heading">
    <div><h1>Kinder & Familien</h1><p class="muted">Kontakte, Betreuungsansprüche und Kita-Zugehörigkeit verwalten.</p></div>
    <x-button :href="route('children.create')" icon="plus" text="Kind aufnehmen" />
</div>
<form class="mb-6 flex max-w-lg flex-wrap items-end gap-3" method="GET">
    <div class="min-w-48 grow"><x-input label="Kind suchen" name="search" :value="$search" icon="magnifying-glass" placeholder="Vor- oder Nachname" /></div>
    <x-button submit color="gray" outline text="Suchen" />
</form>
<x-card bordered shadowless paddingless>
    <x-table :headers="[
        ['index' => 'child', 'label' => 'Kind'],
        ['index' => 'group', 'label' => 'Gruppe / Kita-Jahr'],
        ['index' => 'contacts', 'label' => 'Elternkontakte'],
        ['index' => 'position', 'label' => 'Warteliste'],
        ['index' => 'status', 'label' => 'Status'],
        ['index' => 'action', 'label' => ''],
    ]" :rows="$children" :sort="[]">
        @interact('column_child', $child)
            <span class="font-semibold text-gray-900">{{ $child->first_name }} {{ $child->last_name }}</span>
        @endinteract
        @interact('column_group', $child)
            {{ $child->group->name }}<p class="mt-1 text-xs text-gray-500">{{ $child->kitaYear->name }}</p>
        @endinteract
        @interact('column_contacts', $child)
            @foreach($child->family->contacts as $contact)
                <div class="mb-2 last:mb-0"><p>{{ $contact->name }}</p><p class="mt-0.5 text-xs text-gray-500">{{ $contact->email }}</p></div>
            @endforeach
        @endinteract
        @interact('column_position', $child)
            @if($child->waitingListEntry)<span class="position">{{ $child->waitingListEntry->position }}</span>@else<span class="text-xs text-gray-500">Grundliste ausstehend</span>@endif
        @endinteract
        @interact('column_status', $child)
            <x-badge :color="$child->active ? 'indigo' : 'gray'" light>{{ $child->active ? 'Aktiv' : 'Ausgeschieden' }}</x-badge>
        @endinteract
        @interact('column_action', $child)
            <x-button :href="route('children.edit', $child)" flat sm text="Bearbeiten" icon="pencil-square" />
        @endinteract
        <x-slot:empty>
            <div class="py-8 text-center"><x-icon icon="users" class="mx-auto mb-3 size-9 text-gray-300" /><p>Noch keine passenden Kinder vorhanden.</p></div>
        </x-slot:empty>
    </x-table>
    @if($children->hasPages())
        <x-slot:footer unwrapped>{{ $children->links() }}</x-slot:footer>
    @endif
</x-card>
@endsection
