@extends('layouts.app')
@section('title', 'Vergabe #'.$allocation->id)
@section('content')
@php($statusLabels = ['responses'=>'Rückmeldungen offen','closed'=>'Rückmeldungen geschlossen','proposed'=>'Unverbindlicher Vorschlag','confirmed'=>'Verbindlich abgeschlossen'])
@php($responsesExpired = $allocation->status === 'responses' && $allocation->response_deadline && $allocation->response_deadline->lte(now()))
<div class="page-heading"><div><p class="eyebrow">{{ $allocation->emergencyDay->group->name }} · {{ $allocation->emergencyDay->kitaYear->name }} · Verfahren #{{ $allocation->id }}</p><h1>{{ $allocation->emergencyDay->date->translatedFormat('l, d. F Y') }}</h1><p class="muted">{{ $allocation->emergencyDay->careTimeLabel() }} · {{ $allocation->capacity }} Plätze · {{ $responsesExpired ? 'Rückmeldefrist abgelaufen' : $statusLabels[$allocation->status] }}</p></div><x-button color="gray" outline href="{{ route('dashboard') }}">Zur Übersicht</x-button></div>
@if($allocation->previous_allocation_id)<div class="mb-6"><x-card bordered shadowless><p class="text-sm">Folgeverfahren zu <x-button flat sm href="{{ route('allocations.show', $allocation->previous_allocation_id) }}">#{{ $allocation->previous_allocation_id }}</x-button>: {{ $allocation->reason }}</p></x-card></div>@endif
<div class="mb-6 grid gap-4 md:grid-cols-4">@foreach(['Kapazität'=>$allocation->capacity,'Antworten'=>$allocation->responses->whereNotNull('responded_at')->count().' / '.$allocation->responses->count(),'Fehlende Antworten'=>$allocation->responses->whereNull('responded_at')->count(),'Rückmeldefrist'=>$allocation->response_deadline?->format('d.m.Y H:i') ?? 'Manueller Abschluss'] as $title=>$value)<div><x-card bordered shadowless><p class="text-xs text-gray-500">{{ $title }}</p><p class="mt-3 text-xl font-semibold">{{ $value }}</p></x-card></div>@endforeach</div>
@if($allocation->status === 'responses' && !$responsesExpired)
<div class="mb-6"><x-card bordered shadowless><form  method="POST" action="{{ route('allocations.deadline.update', $allocation) }}">
    @csrf @method('PUT')
    <h2>Rückmeldefrist ändern</h2>
    <div class="mt-4 flex flex-wrap items-end gap-4">
        <div><x-input label="Rückmeldefrist (optional)" type="datetime-local" name="response_deadline" value="{{ old('response_deadline', $allocation->response_deadline?->format('Y-m-d\TH:i')) }}" /></div>
        <x-button submit color="gray" outline>Frist speichern</x-button>
    </div>
    <p class="muted !text-xs">Alle Uhrzeiten: Europe/Berlin. Ein leeres Feld entfernt die Frist; die Phase wird dann manuell geschlossen. Bereits versendete E-Mails werden nicht automatisch erneut versendet. Bei Bedarf können Sie die Anfragen erneut senden.</p>
</form></x-card></div>
<div class="mb-6"><x-card bordered shadowless><h2>Rückmeldungsphase</h2><p class="muted mb-5">Eltern geben ihren regulären Bedarf oder Nachrückbedarf an. Fehlende Antworten lassen den regulären Anspruch bestehen.</p><div class="flex flex-wrap gap-3">@foreach(['request'=>'Anfragen versenden / erneut senden','remind'=>'Fehlende Antworten erinnern','close'=>'Rückmeldungsphase schließen'] as $action=>$label)<form method="POST" action="{{ route('allocations.action', $allocation) }}">@csrf<input type="hidden" name="action" value="{{ $action }}"><x-button submit :outline="$action !== 'close'">{{ $label }}</x-button></form>@endforeach</div><p class="muted !text-xs">Ein manueller Abschluss ist jederzeit möglich, auch sobald alle Antworten vorliegen. Nach Ablauf der Frist sind Änderungen durch Eltern gesperrt.</p></x-card></div>
@endif
@if(in_array($allocation->status, ['closed', 'proposed']) || $responsesExpired)
<div class="mb-6"><x-card bordered shadowless>
    <form method="POST" action="{{ route('allocations.responses.reopen', $allocation) }}">
        @csrf
        <h2>Rückmeldungsphase wieder eröffnen</h2>
        <p class="muted">Bisherige Antworten bleiben erhalten. Gültige persönliche Elternlinks können wieder für Rückmeldungen verwendet werden. Widerrufene oder abgelaufene Links bleiben gesperrt.</p>
        @if($allocation->status === 'proposed')<p class="mt-3 text-sm text-amber-800">Der aktuelle Vergabevorschlag wird verworfen und muss nach Ende der neuen Rückmeldungsphase neu berechnet werden.</p>@endif
        <div class="mt-4 flex flex-wrap items-end gap-4">
            <div><x-input label="Neue Rückmeldefrist (optional)" type="datetime-local" name="response_deadline" value="{{ old('response_deadline', $allocation->response_deadline?->isFuture() ? $allocation->response_deadline->format('Y-m-d\TH:i') : '') }}" /></div>
            <x-button submit color="gray" outline>Rückmeldungsphase wieder eröffnen</x-button>
        </div>
        <p class="muted !text-xs">Eine neue Frist muss in der Zukunft liegen. Ohne Frist schließen Sie die Phase manuell. Alle Uhrzeiten: Europe/Berlin. Es werden keine neuen E-Mails beauftragt; Anfragen können Sie bei Bedarf anschließend selbst versenden.</p>
    </form>
    @if($responsesExpired)
        <form class="mt-4" method="POST" action="{{ route('allocations.action', $allocation) }}">@csrf<input type="hidden" name="action" value="close"><x-button submit color="gray" flat>Geschlossene Rückmeldungen zur Vergabe übernehmen</x-button></form>
    @endif
</x-card></div>
@endif
<div class="mb-6"><x-card bordered shadowless class="overflow-x-auto"><h2>Ansprüche & Rückmeldungen</h2><p class="muted mb-6">Gespeicherte Anspruchsregeln dieses Verfahrens. Der reguläre Anspruch wird anhand von Wochentag und Kalenderwoche ermittelt. Bis zur verbindlichen Bestätigung können Sie Antworten im Namen der Eltern erfassen oder ändern, auch nach Fristende oder Schließung der Rückmeldungsphase. Diese Änderungen werden mit Ihrem Administratorkonto protokolliert.</p>
<table class="data-table"><thead><tr><th>Kind</th><th>Regulärer Anspruch</th><th>Regulärer Bedarf</th><th>Nachrückbedarf</th><th>Antwort</th></tr></thead><tbody>
@foreach($allocation->responses as $response)<tr><td class="font-semibold">{{ $response->child->first_name }} {{ $response->child->last_name }}@if(!$response->child->active)<p class="text-xs text-gray-500">Ausgeschieden</p>@endif</td><td><x-badge color="{{ $response->claim_snapshot['status'] === 'ambiguous' ? 'amber' : 'indigo' }}" light>{{ ['full'=>'Regulärer Anspruch','partial'=>'Regulärer Anspruch','none'=>'Kein Anspruch','ambiguous'=>'Klärung erforderlich'][$response->claim_snapshot['status']] }}</x-badge><p class="mt-2 text-xs text-gray-500">{{ $response->claim_snapshot['explanation'] ?? '' }}</p>@foreach($response->claim_snapshot['rules'] as $rule)<p class="mt-1 text-xs">{{ ['always'=>'Immer','never'=>'Nie','even'=>'Gerade KW','odd'=>'Ungerade KW'][$rule['pattern']] }}</p>@endforeach
</td><td>{{ $response->regular_needed === null ? 'Standardanspruch' : ($response->regular_needed ? 'Benötigt' : 'Freigegeben') }}</td><td>{{ $response->waiting_needed ? 'Ja' : 'Nein' }}</td><td><x-badge color="{{ $response->responded_at ? 'indigo' : 'amber' }}" light>{{ $response->responded_at ? $response->responded_at->format('d.m. H:i') : 'Fehlt' }}</x-badge>
@if(in_array($allocation->status, ['responses', 'closed', 'proposed']) && $response->child->active && $response->child->group_id === $allocation->emergencyDay->group_id && $response->claim_snapshot['status'] !== 'ambiguous')
<details class="mt-3"><summary class="cursor-pointer text-xs text-indigo-800 underline">{{ $response->responded_at ? 'Rückmeldung ändern' : 'Rückmeldung erfassen' }}</summary>
<form method="POST" action="{{ route('responses.admin.update', $response) }}" class="mt-4 min-w-64 space-y-4">
    @csrf @method('PUT')
    <p class="text-xs text-gray-500">Im Namen der Eltern von {{ $response->child->first_name }} {{ $response->child->last_name }}</p>
    @if($response->claim_snapshot['status'] === 'full')
        <div><x-select.native label="Regulärer Platz" aria-label="Regulärer Platz" name="regular_needed" required><option value="" @selected($response->regular_needed === null)>Bitte wählen</option><option value="1" @selected($response->regular_needed === true)>Wird benötigt</option><option value="0" @selected($response->regular_needed === false)>Wird nicht benötigt</option></x-select.native></div>
        <input type="hidden" name="waiting_needed" value="0">
    @else
        <input type="hidden" name="waiting_needed" value="0"><x-checkbox name="waiting_needed" id="waiting-needed-{{ $response->id }}" value="1" :checked="$response->waiting_needed" label="Nachrückplatz wird benötigt" />
    @endif
    @if($allocation->status === 'proposed')<p class="text-xs leading-relaxed text-amber-800">Geänderte Angaben verwerfen den aktuellen Vorschlag. Danach muss die Vergabe neu berechnet werden.</p>@endif
    <x-button submit color="gray" outline>Im Namen der Eltern speichern</x-button>
</form></details>
@endif
</td></tr>@endforeach
</tbody></table></x-card></div>
@if($allocation->status === 'closed')<div class="mb-6"><x-card bordered shadowless><form class="flex flex-wrap items-center justify-between gap-4" method="POST" action="{{ route('allocations.action', $allocation) }}">@csrf<input type="hidden" name="action" value="propose"><div><h2>Bereit für den Vergabevorschlag</h2><p class="muted">Die Warteliste bleibt bei der Berechnung unverändert.</p></div><x-button submit>Vorschlag berechnen</x-button></form></x-card></div>@endif
@if($allocation->proposal)
@php($proposal = $allocation->proposal)
<div class="mb-6"><x-card bordered shadowless class="overflow-x-auto"><div class="mb-6 flex flex-wrap items-start justify-between gap-4"><div><p class="eyebrow">{{ $allocation->status === 'confirmed' ? 'Verbindliches Ergebnis' : 'Zur Prüfung · noch unverbindlich' }}</p><h2>Vorgeschlagene Platzverteilung</h2><p class="muted">Berechnet {{ $allocation->calculated_at->format('d.m.Y H:i') }} · {{ $proposal['remaining'] }} Plätze bleiben frei.</p></div><x-badge color="indigo" light>{{ $proposal['capacity'] - $proposal['remaining'] }} / {{ $proposal['capacity'] }} Plätze vergeben</x-badge></div>
<table class="data-table"><thead><tr><th>Kind</th><th>Phase</th><th>Ergebnis</th><th>Wartelistenposition</th><th>Begründung</th></tr></thead><tbody>@foreach($proposal['rows'] as $row)<tr><td class="font-semibold">{{ $row['name'] }}</td><td>{{ $row['phase'] ? 'Phase '.$row['phase'] : '—' }}</td><td><x-badge color="{{ $row['awarded'] ? 'indigo' : 'amber' }}" light>{{ $row['awarded'] ? 'Platz erhalten' : 'Kein Platz' }}</x-badge><p class="mt-1 text-xs text-gray-500">{{ $row['offered'] ? 'Angeboten' : 'Nicht angeboten' }} · {{ $row['takes_place'] ? 'Inanspruchnahme' : 'Keine Inanspruchnahme' }}</p></td><td>{{ $row['original_position'] ?? '—' }} → {{ $row['new_position'] ?? '—' }}</td><td class="max-w-sm text-xs leading-relaxed text-gray-600">{{ $row['reason'] }}</td></tr>@endforeach</tbody></table>
</x-card></div>
@if($allocation->status === 'proposed')<div class="mb-6"><x-card bordered shadowless><h2>Vollständigen Vorschlag bestätigen</h2><p class="muted mb-5">Erst jetzt werden die Vergabe und die angezeigten Wartelistenbewegungen verbindlich gespeichert. Danach werden Ergebnis-E-Mails zum Versand vorgemerkt.</p><form method="POST" action="{{ route('allocations.action', $allocation) }}" class="space-y-4">@csrf<input type="hidden" name="action" value="confirm"><input type="hidden" name="proposal_hash" value="{{ $allocation->proposalHash() }}"><x-checkbox name="confirmation" value="1" required label="Ich habe den vollständigen Vorschlag einschließlich Listenbewegungen geprüft und bestätige die Vergabe ausdrücklich." /><x-button submit>Vergabe verbindlich bestätigen</x-button></form><form class="mt-4" method="POST" action="{{ route('allocations.action', $allocation) }}">@csrf<input type="hidden" name="action" value="discard"><x-button submit color="gray" flat sm>Vorschlag verwerfen und zur Berechnung zurückkehren</x-button></form></x-card></div>@endif
@endif
@if($allocation->status === 'confirmed')<div class="mb-6"><x-card bordered shadowless><h2>Abgeschlossen am {{ $allocation->confirmed_at->format('d.m.Y H:i') }}</h2><p class="muted">Änderungen nach einer verbindlichen Bestätigung erfordern ein neues Verfahren mit eigener Rückmeldung und Bestätigung.</p><details class="mt-5"><summary class="cursor-pointer font-medium text-indigo-800">Neues Verfahren für diesen Tag anlegen</summary><form method="POST" action="{{ route('allocations.restart', $allocation) }}" class="mt-5 grid items-end gap-4 md:grid-cols-2">@csrf<div><x-input label="Neue Kapazität" type="number" min="0" max="10000" name="capacity" required /></div><div><x-input label="Neue Rückmeldefrist (optional)" type="datetime-local" name="response_deadline" /></div><div class="md:col-span-2"><x-textarea label="Geänderte Situation / Begründung" name="reason" required maxlength="2000"></x-textarea></div><x-button submit class="w-fit" >Neues Verfahren eröffnen</x-button></form></details></x-card></div>@endif
<div class="mb-6"><x-card bordered shadowless><details><summary class="cursor-pointer font-semibold">E-Mail-Versand · {{ $deliveries->whereNotNull('sent_at')->count() }} / {{ $deliveries->count() }} versendet</summary><div class="mt-5 overflow-x-auto"><table class="data-table"><thead><tr><th>Empfänger</th><th>Art</th><th>Versuche</th><th>Status</th></tr></thead><tbody>@forelse($deliveries as $delivery)<tr><td>{{ $delivery->payload['email'] }}</td><td>{{ ['request'=>'Rückmeldungsanfrage','reminder'=>'Erinnerung','result'=>'Ergebnis'][$delivery->kind] }}</td><td>{{ $delivery->attempts }}</td><td>{{ $delivery->sent_at?->format('d.m.Y H:i') ?? ($delivery->last_error ?? 'Zum Versand vorgemerkt') }}<form class="mt-2" method="POST" action="{{ route('mail.resend', $delivery) }}">@csrf<x-button submit flat sm>Erneut versenden</x-button></form></td></tr>@empty<tr><td colspan="4">Noch keine Versandaufträge.</td></tr>@endforelse</tbody></table></div></details></x-card></div>
<div><x-card bordered shadowless>
    <details>
        <summary class="cursor-pointer font-semibold">Persönliche Elternlinks verwalten</summary>
        <p class="muted mb-4">Jeder Link gilt nur für ein Kind in diesem Verfahren. Widerrufene oder abgelaufene Links können nicht mehr verwendet oder kopiert werden.</p>
        <div class="overflow-x-auto"><table class="data-table">
            <thead><tr><th>Kind</th><th>Kontakt</th><th>Gültig bis</th><th>Status</th><th>Aktionen</th></tr></thead>
            <tbody>
                @foreach($tokens as $token)
                    @php($usable = !$token->revoked_at && (!$token->expires_at || $token->expires_at->isFuture()))
                    <tr>
                        <td>{{ $token->response->child->first_name }}</td>
                        <td>{{ $token->parentContact->email }}</td>
                        <td>{{ $token->expires_at?->format('d.m.Y') ?? 'Unbegrenzt' }}</td>
                        <td>{{ $token->revoked_at ? 'Widerrufen' : ($usable ? 'Gültig' : 'Abgelaufen') }}</td>
                        <td>
                            @if($usable)
                                <div x-data="{
                                    copying: false, message: '', failed: false,
                                    async copyLink() {
                                        this.copying = true;
                                        this.message = '';
                                        this.failed = false;
                                        try {
                                            if (!navigator.clipboard?.writeText) {
                                                this.message = 'Die Zwischenablage ist in diesem Browser nicht verfügbar. Bitte eine sichere HTTPS-Verbindung verwenden.';
                                                throw new Error();
                                            }
                                            const response = await fetch(@js(route('tokens.copy', $token)), { headers: { Accept: 'application/json' }, cache: 'no-store' });
                                            if (!response.ok) {
                                                const data = await response.json();
                                                this.message = [404, 422].includes(response.status) ? data.message : 'Der Link konnte nicht geladen werden. Bitte die Seite neu laden und erneut versuchen.';
                                                throw new Error();
                                            }
                                            const data = await response.json();
                                            await navigator.clipboard.writeText(data.url);
                                            this.message = 'Link kopiert.';
                                        } catch (error) {
                                            this.failed = true;
                                            this.message = error.name === 'NotAllowedError' ? 'Kopieren nicht erlaubt. Bitte den Zugriff auf die Zwischenablage erlauben und erneut versuchen.' : (this.message || 'Kopieren fehlgeschlagen. Bitte Verbindung und Zwischenablage prüfen und erneut versuchen.');
                                        } finally {
                                            this.copying = false;
                                        }
                                    }
                                }">
                                    <div class="flex flex-wrap gap-2">
                                        <x-button type="button" color="gray" outline sm x-on:click="copyLink()" x-bind:disabled="copying" icon="clipboard" text="Link kopieren" />
                                        <form method="POST" action="{{ route('tokens.revoke', $token) }}">@csrf<x-button submit color="red" flat sm>Link widerrufen</x-button></form>
                                    </div>
                                    <p role="status" aria-live="polite" class="mt-2 text-xs" x-bind:class="failed ? 'text-red-600' : 'text-green-700'" x-text="message"></p>
                                </div>
                            @elseif(!$token->revoked_at)
                                <form method="POST" action="{{ route('tokens.revoke', $token) }}">@csrf<x-button submit color="red" flat sm>Link widerrufen</x-button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </details>
</x-card></div>
@endsection
