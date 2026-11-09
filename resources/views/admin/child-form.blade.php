@extends('layouts.app')
@section('title', $child ? 'Kind bearbeiten' : 'Kind aufnehmen')
@section('content')
<div class="page-heading"><div><h1>{{ $child ? $child->first_name.' '.$child->last_name : 'Ein neues Kind aufnehmen' }}</h1></div><x-button color="gray" outline href="{{ route('children.index') }}">Zur Kinderliste</x-button></div>
@if($groups->isEmpty())<div class="mb-6"><x-card bordered shadowless><p>Bitte zuerst unter „Gruppen & Admins“ eine Gruppe anlegen.</p></x-card></div>@endif
@php
    $familyContacts = $families->mapWithKeys(fn ($family) => [$family->id => $family->contacts->map(fn ($contact) => $contact->only('id', 'name', 'email'))->all()]);
    $initialContacts = old('contacts', $familyContacts->get((int) old('family_id', $child?->family_id), []));
    $initialContacts = collect(is_array($initialContacts) ? $initialContacts : [])->filter(fn ($contact) => is_array($contact))->map(fn ($contact) => [
        'id' => is_scalar($contact['id'] ?? null) ? $contact['id'] : null,
        'name' => is_string($contact['name'] ?? null) ? $contact['name'] : '',
        'email' => is_string($contact['email'] ?? null) ? $contact['email'] : '',
    ])->values()->all();
@endphp
<form method="POST" action="{{ $child ? route('children.update', $child) : route('children.store') }}" class="space-y-6"
    x-data="{
        contacts: @js($initialContacts),
        familyContacts: @js($familyContacts),
        contactErrors: @js($errors->toArray()),
        nextKey: 0,
        init() {
            this.contacts = this.contacts.map(contact => ({ ...contact, key: this.nextKey++ }));
            this.ensureBlank();
        },
        ensureBlank() {
            const blank = this.contacts.find(contact => !contact.id && !contact.name.trim() && !contact.email.trim())
                || { id: null, name: '', email: '', key: this.nextKey++ };
            this.contacts = this.contacts.filter(contact => contact.id || contact.name.trim() || contact.email.trim());
            this.contacts.push(blank);
        },
        selectFamily(id) {
            this.contacts = (this.familyContacts[id] || []).map(contact => ({ ...contact, key: this.nextKey++ }));
            this.contactErrors = {};
            this.ensureBlank();
        }
    }">@csrf @if($child)@method('PUT')@endif
<div><x-card bordered shadowless><h2 class="mb-5">Kind & Familie</h2><div class="grid gap-5 md:grid-cols-2"><div><x-input label="Vorname" name="first_name" value="{{ old('first_name', $child?->first_name) }}" required maxlength="100" /></div><div><x-input label="Nachname" name="last_name" value="{{ old('last_name', $child?->last_name) }}" required maxlength="100" /></div><div><x-select.native label="Gruppe" aria-label="Gruppe" name="group_id" required>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('group_id', $child?->group_id) == $group->id)>{{ $group->name }}</option>@endforeach</x-select.native></div><div><x-select.native label="Kita-Jahr" aria-label="Kita-Jahr" name="kita_year_id" required>@foreach($years as $year)<option value="{{ $year->id }}" @selected(old('kita_year_id', $child?->kita_year_id) == $year->id)>{{ $year->name }}</option>@endforeach</x-select.native></div><div><x-select.native label="Status" aria-label="Status" name="active"><option value="1" @selected(old('active', $child?->active ?? true))>Aktiv</option><option value="0" @selected(!old('active', $child?->active ?? true))>Ausgeschieden</option></x-select.native></div><div><x-select.native label="Familie auswählen" aria-label="Familie auswählen" name="family_id" x-on:change="selectFamily($event.target.value)"><option value="">Neue Familie anlegen</option>@foreach($families as $family)<option value="{{ $family->id }}" @selected(old('family_id', $child?->family_id) == $family->id)>{{ $family->group->name }} · {{ $family->name }} · {{ $family->contacts->pluck('email')->join(', ') }}</option>@endforeach</x-select.native></div><div><x-input label="Name für neue Familie" name="family_name" value="{{ old('family_name') }}" placeholder="Familie Müller" /></div></div><p class="muted">Familie aus derselben Gruppe für Geschwister oder das nächste Kita-Jahr auswählen. Jedes Kind erhält einen eigenen Wartelisteneintrag.</p></x-card></div>
<div><x-card bordered shadowless>
    <h2>Elternkontakte</h2>
    <p class="muted mb-5">Kontakte hier bearbeiten oder ergänzen. Sobald Sie die freie Zeile ausfüllen, erscheint die nächste. Leere Zeilen werden ignoriert. Änderungen gelten für alle Kinder der ausgewählten Familie.</p>
    @error('contacts')<p class="mb-4 text-sm text-red-600" role="alert">{{ $message }}</p>@enderror
    <div x-on:input="ensureBlank()" class="space-y-5">
        <template x-for="(contact, index) in contacts" :key="contact.key">
            <fieldset class="grid gap-4 border-t border-gray-100 pt-4 md:grid-cols-2">
                <legend class="text-sm font-medium text-gray-600" x-text="contact.id ? 'Kontakt ' + (index + 1) : 'Weiterer Kontakt'"></legend>
                <input type="hidden" x-bind:name="'contacts[' + index + '][id]'" x-bind:value="contact.id || ''">
                <div>
                    <label class="mb-1 block" x-bind:for="'contact-name-' + contact.key">Name</label>
                    <x-input x-bind:id="'contact-name-' + contact.key" x-bind:name="'contacts[' + index + '][name]'" x-model="contact.name" maxlength="150" x-bind:required="Boolean(contact.id || contact.name.trim() || contact.email.trim())" />
                    <p class="mt-1 text-sm text-red-600" role="alert" x-show="contactErrors['contacts.' + index + '.name']" x-text="contactErrors['contacts.' + index + '.name']?.[0]"></p>
                </div>
                <div>
                    <label class="mb-1 block" x-bind:for="'contact-email-' + contact.key">E-Mail-Adresse</label>
                    <x-input type="email" x-bind:id="'contact-email-' + contact.key" x-bind:name="'contacts[' + index + '][email]'" x-model="contact.email" maxlength="255" x-bind:required="Boolean(contact.id || contact.name.trim() || contact.email.trim())" />
                    <p class="mt-1 text-sm text-red-600" role="alert" x-show="contactErrors['contacts.' + index + '.email']" x-text="contactErrors['contacts.' + index + '.email']?.[0]"></p>
                </div>
                <p class="text-sm text-red-600 md:col-span-2" role="alert" x-show="contactErrors['contacts.' + index + '.id']" x-text="contactErrors['contacts.' + index + '.id']?.[0]"></p>
            </fieldset>
        </template>
    </div>
</x-card></div>
<div><x-card bordered shadowless class="overflow-x-auto"><h2>Reguläre Betreuungsansprüche</h2><p class="muted mb-6">Alle Wochentage folgen denselben Regeln. Gerade/ungerade bezieht sich auf die ISO-Kalenderwoche. Die Gültigkeit wird pro Wochentag festgelegt.</p>
@foreach([1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag'] as $weekday=>$label)
@php($rule = $child?->assignments->firstWhere('weekday', $weekday))
<div class="grid gap-4 border-t border-gray-100 py-5 md:grid-cols-3 xl:grid-cols-4"><div class="pt-2 font-semibold">{{ $label }}<input type="hidden" name="rules[{{ $weekday-1 }}][weekday]" value="{{ $weekday }}"></div><div><x-select.native label="Anspruch" aria-label="Anspruch" name="rules[{{ $weekday-1 }}][pattern]">@foreach(['always'=>'Immer','never'=>'Nie','even'=>'Gerade KW','odd'=>'Ungerade KW'] as $key=>$name)<option value="{{ $key }}" @selected(old('rules.'.($weekday-1).'.pattern', $rule?->pattern ?? 'never') === $key)>{{ $name }}</option>@endforeach</x-select.native></div><div><x-input label="Gültig ab" type="date" name="rules[{{ $weekday-1 }}][valid_from]" value="{{ old('rules.'.($weekday-1).'.valid_from', $rule?->valid_from?->format('Y-m-d') ?? $years->first()?->starts_on->format('Y-m-d')) }}" required /></div><div><x-input label="Gültig bis" type="date" name="rules[{{ $weekday-1 }}][valid_until]" value="{{ old('rules.'.($weekday-1).'.valid_until', $rule?->valid_until?->format('Y-m-d')) }}" /></div></div>
@endforeach</x-card></div>
<div class="form-actions"><p class="text-xs text-gray-500">Abgeschlossene Vergaben behalten ihre historischen Ansprüche.</p><x-button submit>Änderungen speichern</x-button></div></form>
@endsection
