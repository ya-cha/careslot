@extends('layouts.app')
@section('title', 'Ihre Rückmeldung')
@section('content')
@php($regular = in_array($response->claim_snapshot['status'], ['full','partial']))
@php($closed = $allocation->status !== 'responses' || ($allocation->response_deadline && $allocation->response_deadline->lte(now())))
@php($unresolved = $response->claim_snapshot['status'] === 'ambiguous')
<div class="mb-8">
    <p class="eyebrow">Ihre Betreuung</p>
    <h1>Hallo, Familie {{ $response->child->last_name }}.</h1>
    <p class="muted">Rückmeldung für {{ $response->child->first_name }} · {{ $allocation->emergencyDay->date->translatedFormat('l, d. F Y') }}</p>
</div>
<div class="mb-6">
    <x-card bordered shadowless>
        <div class="flex items-start gap-4">
            <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><x-icon icon="calendar-days" class="size-6" /></span>
            <div>
                <h2>Notbetreuung am {{ $allocation->emergencyDay->date->format('d.m.Y') }}</h2>
                <p class="muted">{{ $allocation->emergencyDay->careTimeLabel() }}.</p>
                <p class="muted">{{ $unresolved ? 'Anspruchsregeln fehlen oder sind widersprüchlich.' : ($regular ? 'Regulärer Anspruch für diesen Betreuungstag.' : 'Kein regulärer Anspruch für diesen Betreuungstag.') }}</p>
                @if($allocation->response_deadline)<p class="mt-4 text-xs font-medium text-gray-600">Rückmeldefrist: {{ $allocation->response_deadline->format('d.m.Y H:i') }} Uhr (Europe/Berlin)</p>@endif
            </div>
        </div>
    </x-card>
</div>
@if($allocation->status === 'confirmed')
    @php($result = $allocation->results()->where('child_id', $response->child_id)->first())
    <x-card bordered shadowless>
        <h2>{{ $result?->awarded ? 'Ein Platz für '.$response->child->first_name.'.' : 'In diesem Verfahren wurde kein Platz vergeben.' }}</h2>
        <p class="muted">{{ $result?->reason }}</p>
        @if($result?->awarded)<p class="mt-4 text-sm">{{ $allocation->emergencyDay->careTimeLabel() }}</p>@endif
    </x-card>
@elseif($closed)
    <x-alert color="indigo" light icon="lock-closed" title="Die Rückmeldungsphase ist geschlossen." text="Ihre Angaben sind gespeichert. Sobald der Administrator die Vergabe bestätigt hat, erhalten Sie das verbindliche Ergebnis per E-Mail." />
@elseif($unresolved)
    <x-alert color="amber" light icon="information-circle" title="Ihr Betreuungsanspruch wird geklärt." text="Bitte wenden Sie sich an den Administrator. Danach können Sie Ihren Bedarf hier angeben." />
@else
    <x-card bordered shadowless header="Betreuungsbedarf">
        <form class="space-y-6" method="POST" action="{{ route('parent.update', $token) }}">
            @csrf
            @if($regular)
                <fieldset class="space-y-4">
                    <legend class="mb-3 text-sm font-semibold">Benötigen Sie den regulären Platz?</legend>
                    <x-radio id="regular-needed-yes" name="regular_needed" value="1" :checked="old('regular_needed', $response->regular_needed) === true || old('regular_needed') === '1'" required label="Ja, wir benötigen den regulären Platz." />
                    <x-radio id="regular-needed-no" name="regular_needed" value="0" :checked="old('regular_needed', $response->regular_needed) === false || old('regular_needed') === '0'" required label="Nein, wir geben den regulären Platz frei." />
                </fieldset>
                <input type="hidden" name="waiting_needed" value="0">
            @else
                <input type="hidden" name="waiting_needed" value="0">
                <div>
                    <x-checkbox id="waiting-needed" name="waiting_needed" value="1" :checked="(bool) old('waiting_needed', $response->waiting_needed)" label="Wir benötigen einen möglichen Nachrückplatz." />
                    <p class="mt-2 pl-8 text-xs leading-relaxed text-gray-500">Freie Plätze werden nach der Wartelistenreihenfolge vergeben. Eine Absage verändert Ihre Position nicht.</p>
                </div>
            @endif
            <x-alert color="gray" light icon="information-circle" text="Ohne Rückmeldung bleibt ein regulärer Standardanspruch bestehen. Für die Warteliste wird kein Bedarf angenommen. Eine Rückmeldung ist noch keine verbindliche Platzbestätigung." />
            <x-button submit block text="Rückmeldung speichern" icon="check" />
            @if($response->responded_at)<p class="text-center text-xs text-gray-500">Zuletzt gespeichert: {{ $response->responded_at->format('d.m.Y H:i') }} Uhr</p>@endif
        </form>
    </x-card>
@endif
<p class="mt-6 text-xs leading-relaxed text-gray-500">Dieser persönliche Link ist ausschließlich für Ihre Familie bestimmt. Bitte geben Sie ihn nicht weiter. Bei Fragen hilft Ihnen der Administrator Ihrer Kita.</p>
@endsection
