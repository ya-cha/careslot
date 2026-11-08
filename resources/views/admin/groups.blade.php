@extends('layouts.app')
@section('title', 'Gruppen & Admins')
@section('content')
<div class="page-heading"><div><h1>Gruppen & Admins</h1><p class="muted">Benutzer und Berechtigungen nach Gruppen verwalten.</p></div></div>
<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
    <div class="min-w-0 space-y-5">
        <section aria-label="Gesamtverwaltung">
            <x-card bordered shadowless header="Gesamtverwaltung">
                <p class="mb-5 text-sm text-gray-500">Diese Admins haben Zugriff auf alle Gruppen.</p>
                <div class="divide-y divide-gray-100">
                    @foreach($systemAdministrators as $user)
                        <x-user-management-row :user="$user" :groups="$groups" />
                    @endforeach
                </div>
            </x-card>
        </section>
        @foreach($groups as $group)
            <section aria-label="{{ $group->name }}">
                <x-card bordered shadowless>
                    <x-slot:header><div class="flex min-w-0 items-center gap-3"><x-icon icon="building-office-2" class="size-5 shrink-0 text-indigo-500" /><h2 class="break-words">{{ $group->name }}</h2></div><x-badge color="gray" light>{{ $group->children_count }} Kinder</x-badge></x-slot:header>
                    <form method="POST" action="{{ route('groups.update', $group) }}" class="flex flex-wrap items-end gap-3">
                        @csrf @method('PUT')
                        <div class="min-w-40 grow"><x-input id="group-name-{{ $group->id }}" label="Gruppenname" name="name" :value="$group->name" required maxlength="150" /></div>
                        <x-button submit color="gray" outline text="Speichern" />
                    </form>
                    <div class="mt-6 border-t border-gray-100 pt-5">
                        <h3 class="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">Benutzer</h3>
                        <div class="divide-y divide-gray-100">
                            @forelse($usersByGroup->get($group->id, collect()) as $user)
                                <x-user-management-row :user="$user" :groups="$groups" />
                            @empty
                                <p class="muted">Noch keine Benutzer zugeordnet.</p>
                            @endforelse
                        </div>
                    </div>
                </x-card>
            </section>
        @endforeach
        @if($groups->isEmpty())
            <x-card bordered shadowless><p class="text-sm text-gray-500">Noch keine Gruppen vorhanden. Legen Sie rechts die erste Gruppe an.</p></x-card>
        @endif
        @if($unassignedUsers->isNotEmpty())
            <section aria-label="Ohne Gruppenzuordnung">
                <x-card bordered shadowless header="Ohne Gruppenzuordnung">
                    <div class="divide-y divide-gray-100">
                        @foreach($unassignedUsers as $user)
                            <x-user-management-row :user="$user" :groups="$groups" />
                        @endforeach
                    </div>
                </x-card>
            </section>
        @endif
    </div>
    <aside aria-label="Gruppen und Admins erstellen" class="min-w-0 space-y-6">
        <x-card bordered shadowless header="Gruppe anlegen">
            <form class="space-y-5" method="POST" action="{{ route('groups.store') }}">
                @csrf
                <x-input id="new-group-name" label="Name" name="name" required maxlength="150" />
                <x-button submit block text="Gruppe anlegen" icon="plus" />
            </form>
        </x-card>
        <x-card bordered shadowless header="Admin anlegen">
            <form class="space-y-5" method="POST" action="{{ route('groups.admins.store') }}">
                @csrf
                <x-input id="new-admin-name" label="Name" name="name" required maxlength="150" autocomplete="off" />
                <x-input id="new-admin-email" label="E-Mail-Adresse" type="email" name="email" required maxlength="255" autocomplete="off" />
                <x-password id="new-admin-password" label="Passwort" name="password" required minlength="12" autocomplete="new-password" />
                <x-password id="new-admin-password-confirmation" label="Passwort wiederholen" name="password_confirmation" required minlength="12" autocomplete="new-password" />
                <x-select.native id="new-admin-group" label="Zugriff" name="group_id">
                    @foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('group_id', $groups->first()?->id) == $group->id)>{{ $group->name }}</option>@endforeach
                    <option value="" @selected(!old('group_id', $groups->first()?->id))>Gesamtverwaltung (alle Gruppen)</option>
                </x-select.native>
                <x-button submit block text="Admin anlegen" icon="user-plus" />
            </form>
        </x-card>
    </aside>
</div>
@endsection
