@extends('layouts.app')
@section('title', 'Gruppen & Admins')
@section('content')
<div class="page-heading"><div><h1>Gruppen & Admins</h1><p class="muted">Kinder, Wartelisten und Vergaben werden je Gruppe verwaltet.</p></div></div>
<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
    <div class="space-y-5">
        @forelse($groups as $group)
            <x-card bordered shadowless>
                <x-slot:header><div class="flex items-center gap-3"><x-icon icon="building-office-2" class="size-5 text-indigo-500" /><h2>{{ $group->name }}</h2></div><x-badge color="gray" light>{{ $group->children_count }} Kinder</x-badge></x-slot:header>
                <form method="POST" action="{{ route('groups.update', $group) }}" class="flex flex-wrap items-end gap-3">
                    @csrf @method('PUT')
                    <div class="min-w-40 grow"><x-input id="group-name-{{ $group->id }}" label="Gruppenname" name="name" :value="$group->name" required maxlength="150" /></div>
                    <x-button submit color="gray" outline text="Speichern" />
                </form>
                <div class="mt-6 border-t border-gray-100 pt-5">
                    <h3 class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Gruppenadmins</h3>
                    @forelse($group->administrators as $admin)
                        <div class="mt-4 flex items-center gap-3"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-gray-100 text-xs font-medium text-gray-600">{{ $admin->initials() }}</span><div class="min-w-0"><p class="text-sm font-medium">{{ $admin->name }}</p><p class="truncate text-xs text-gray-500">{{ $admin->email }}</p></div></div>
                    @empty
                        <p class="muted">Noch kein Gruppenadmin zugeordnet.</p>
                    @endforelse
                </div>
            </x-card>
        @empty
            <x-card bordered shadowless><div class="py-10 text-center"><x-icon icon="building-office-2" class="mx-auto mb-4 size-10 text-gray-300" /><h2>Noch keine Gruppen vorhanden</h2><p class="muted">Legen Sie die erste Gruppe an, um Kinder aufzunehmen.</p></div></x-card>
        @endforelse
    </div>
    <div class="space-y-6">
        <x-card bordered shadowless header="Gruppe anlegen">
            <form class="space-y-5" method="POST" action="{{ route('groups.store') }}">
                @csrf
                <x-input id="new-group-name" label="Name" name="name" required maxlength="150" />
                <x-button submit block text="Gruppe anlegen" icon="plus" />
            </form>
        </x-card>
        <x-card bordered shadowless header="Gruppenadmin anlegen">
            <form class="space-y-5" method="POST" action="{{ route('groups.admins.store') }}">
                @csrf
                <x-input id="new-admin-name" label="Name" name="name" required maxlength="150" autocomplete="off" />
                <x-input label="E-Mail-Adresse" type="email" name="email" required maxlength="255" autocomplete="off" />
                <x-password label="Passwort" name="password" required minlength="12" autocomplete="new-password" />
                <x-password label="Passwort wiederholen" name="password_confirmation" required minlength="12" autocomplete="new-password" />
                <x-select.native label="Gruppe" aria-label="Gruppe für neues Konto" name="group_id" required>
                    @foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                </x-select.native>
                <x-button submit block text="Gruppenadmin anlegen" icon="user-plus" />
            </form>
        </x-card>
        <x-card bordered shadowless header="Gruppenadmin zuordnen">
            <form class="space-y-5" method="POST" action="{{ route('groups.admins') }}">
                @csrf
                <x-select.native label="Bestehendes Konto" aria-label="Bestehendes Konto" name="user_id" required>
                    @foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }} · {{ $user->email }}</option>@endforeach
                </x-select.native>
                <x-select.native label="Gruppe" aria-label="Gruppe für bestehendes Konto" name="group_id" required>
                    @foreach($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                </x-select.native>
                <p class="text-xs leading-relaxed text-gray-500">Das Konto erhält ausschließlich Zugriff auf die ausgewählte Gruppe.</p>
                <x-button submit block color="gray" outline text="Zuordnung speichern" />
            </form>
        </x-card>
    </div>
</div>
@endsection
