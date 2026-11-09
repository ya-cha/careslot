@extends('layouts.app')
@section('title', 'Notbetreuung anlegen')
@section('content')
<div class="page-heading">
    <div><h1>Notbetreuung anlegen</h1><p class="muted">Kapazität und Betreuungszeit vor Beginn der Vergabe festlegen.</p></div>
</div>
<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_280px]">
    <x-card bordered shadowless header="Betreuungstag festlegen">
        <form class="space-y-6" method="POST" action="{{ route('days.store') }}">
            @csrf
            <div class="grid gap-5 sm:grid-cols-2">
                <x-select.native label="Gruppe" aria-label="Gruppe" name="group_id" required>
                    @foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('group_id') == $group->id)>{{ $group->name }}</option>@endforeach
                </x-select.native>
                <x-select.native label="Kita-Jahr" aria-label="Kita-Jahr" name="kita_year_id" required>
                    @foreach($years as $year)<option value="{{ $year->id }}" @selected(old('kita_year_id') == $year->id)>{{ $year->name }}</option>@endforeach
                </x-select.native>
            </div>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-input label="Datum" type="date" name="date" :value="old('date')" required />
                <x-input label="Betreuungsbeginn (optional)" type="time" name="starts_at" :value="old('starts_at')" />
                <x-input label="Betreuungsende (optional)" type="time" name="ends_at" :value="old('ends_at')" />
            </div>
            <p class="text-xs text-gray-500">Beginn und Ende können unabhängig voneinander leer bleiben. Ohne beide Uhrzeiten gilt die Notbetreuung ganztägig. Alle Uhrzeiten: Europe/Berlin.</p>
            <div>
                <x-input label="Verfügbare Plätze" type="number" min="0" max="10000" name="capacity" :value="old('capacity')" required />
            </div>
            <div class="border-t border-gray-100 pt-6">
                <h2 class="mb-4">Rückmeldefrist</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="sm:col-span-2"><x-input label="Rückmeldefrist (optional)" type="datetime-local" name="response_deadline" :value="old('response_deadline')" hint="Ohne Frist schließen Sie die Rückmeldungsphase manuell." /></div>
                </div>
            </div>
            <div class="form-actions"><x-button :href="route('dashboard')" color="gray" flat text="Abbrechen" /><x-button submit text="Rückmeldungsphase eröffnen" icon="arrow-right" position="right" /></div>
        </form>
    </x-card>
    <div class="rounded-xl border border-indigo-100 bg-indigo-50/60 p-6">
        <x-icon icon="information-circle" class="mb-4 size-6 text-indigo-500" />
        <h2>So geht es weiter</h2>
        <ol class="mt-4 space-y-4 text-sm leading-relaxed text-gray-600">
            <li><span class="font-medium text-gray-900">1. Rückmeldungen einholen</span><br>Eltern teilen ihren Betreuungsbedarf mit.</li>
            <li><span class="font-medium text-gray-900">2. Vorschlag berechnen</span><br>Ansprüche und Warteliste bestimmen die Vergabe.</li>
            <li><span class="font-medium text-gray-900">3. Vergabe bestätigen</span><br>Erst Ihre ausdrückliche Bestätigung macht die Platzvergabe verbindlich.</li>
        </ol>
    </div>
</div>
@endsection
