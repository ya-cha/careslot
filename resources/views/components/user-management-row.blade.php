@props(['user', 'groups'])

<article aria-label="{{ $user->name }}" class="space-y-4 py-5 first:pt-0 last:pb-0" x-data
    @if(old('user_id') == $user->id) x-init="$nextTick(() => $tsui.open.modal('assign-user-modal-{{ $user->id }}'))" @endif>
    <div class="flex items-center gap-3">
        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-700">{{ $user->initials() }}</span>
        <div class="min-w-0 flex-1">
            <p class="break-words text-sm font-semibold">{{ $user->name }} @if($user->is(auth()->user()))<span class="font-normal text-gray-500">(Sie)</span>@endif</p>
            <p class="break-all text-xs text-gray-500">{{ $user->email }}</p>
        </div>
        <x-badge :color="$user->is_admin ? 'indigo' : 'gray'" light>{{ $user->isSystemAdministrator() ? 'Gesamtverwaltung' : ($user->is_admin ? 'Gruppenadmin' : 'Benutzer') }}</x-badge>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <x-button :href="route('users.edit', $user)" color="gray" outline sm text="Bearbeiten" icon="pencil-square" :aria-label="'Benutzer '.$user->name.' bearbeiten'" />
        @if($groups->isNotEmpty())
            <x-button color="gray" outline sm text="Gruppenadmin zuordnen" icon="user-group" x-on:click="$tsui.open.modal('assign-user-modal-{{ $user->id }}')" aria-haspopup="dialog" aria-controls="assign-user-modal-{{ $user->id }}" :aria-label="'Gruppenadmin '.$user->name.' zuordnen'" />
        @endif
        @can('impersonate')
            @unless($user->is(auth()->user()))
                <form method="POST" action="{{ route('impersonation.store', $user) }}">
                    @csrf
                    <x-button submit color="gray" outline sm text="Als Nutzer anmelden" :aria-label="'Als '.$user->name.' anmelden'" />
                </form>
            @endunless
        @endcan
    </div>
    @if($groups->isNotEmpty())
        <x-modal id="assign-user-modal-{{ $user->id }}" title="Gruppenadmin zuordnen" size="lg" center x-on:open="$tsui.focus('assign-user-{{ $user->id }}')">
            <form method="POST" action="{{ route('groups.admins') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="user_id" value="{{ $user->id }}">
                <p class="text-sm text-gray-600">{{ $user->name }} · {{ $user->email }}</p>
                @if(old('user_id') == $user->id && ($errors->has('user_id') || $errors->has('group_id')))
                    <x-alert color="red" light :text="$errors->first('user_id') ?: $errors->first('group_id')" />
                @endif
                <x-select.native id="assign-user-{{ $user->id }}" label="Gruppe" name="group_id" required>
                    @foreach($groups as $group)<option value="{{ $group->id }}" @selected((old('user_id') == $user->id ? old('group_id') : $user->group_id) == $group->id)>{{ $group->name }}</option>@endforeach
                </x-select.native>
                <p class="text-xs text-gray-500">Das Konto erhält ausschließlich Zugriff auf die ausgewählte Gruppe.</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <x-button color="gray" flat text="Abbrechen" x-on:click="$tsui.close.modal('assign-user-modal-{{ $user->id }}')" />
                    <x-button submit text="Zuordnung speichern" />
                </div>
            </form>
        </x-modal>
    @endif
</article>
