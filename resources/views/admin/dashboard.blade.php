@extends('layouts.app')
@section('title', 'Übersicht')
@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">Betreuungsplanung</p>
        <h1>Übersicht</h1>
        <p class="muted">Rückmeldungen und Vergabeverfahren verwalten.</p>
    </div>
    <x-button :href="route('days.create')" icon="plus" text="Notbetreuung anlegen" />
</div>

<div class="mb-8 grid gap-4 xl:grid-cols-3">
    <x-card bordered shadowless>
        <div class="flex items-start justify-between gap-3">
            <div><p class="text-sm text-gray-500">Aktive Kinder</p><p class="mt-4 text-4xl font-semibold tracking-tight tabular-nums">{{ $childrenCount }}</p></div>
            <span class="grid size-10 place-items-center rounded-xl bg-indigo-50 text-indigo-600"><x-icon icon="users" class="size-5" /></span>
        </div>
        <x-button :href="route('children.index')" flat sm class="mt-4" text="Kinder & Familien ansehen" icon="arrow-right" position="right" />
    </x-card>
    <x-card bordered shadowless>
        <div class="flex items-start justify-between gap-3">
            <div><p class="text-sm text-gray-500">Fehlende Rückmeldungen</p><p class="mt-4 text-4xl font-semibold tracking-tight tabular-nums">{{ $pendingCount }}</p></div>
            <span class="grid size-10 place-items-center rounded-xl bg-amber-50 text-amber-600"><x-icon icon="chat-bubble-left-right" class="size-5" /></span>
        </div>
        <p class="mt-6 text-xs text-gray-500">In laufenden Rückmeldungsphasen</p>
    </x-card>
    <x-card bordered shadowless>
        <div class="flex items-start justify-between gap-3">
            <div><p class="text-sm text-gray-500">Aktuelles Kita-Jahr</p><p class="mt-4 text-3xl font-semibold tracking-tight">{{ $years->firstWhere('status', 'active')?->name ?? 'Noch keines angelegt' }}</p></div>
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-icon icon="calendar" class="size-5" /></span>
        </div>
        <x-button :href="route('years.index')" flat sm class="mt-4" text="Kita-Jahre verwalten" icon="arrow-right" position="right" />
    </x-card>
</div>

<x-card bordered shadowless paddingless>
    <x-slot:header>
        <div><h2>Notbetreuung & Vergaben</h2><p class="muted">Aktuelle Verfahren und abgeschlossene Entscheidungen.</p></div>
        <x-badge color="gray" light>{{ $allocations->total() }} Verfahren</x-badge>
    </x-slot:header>
    <x-table :headers="[
        ['index' => 'day', 'label' => 'Betreuungstag'],
        ['index' => 'capacity', 'label' => 'Plätze'],
        ['index' => 'responses', 'label' => 'Rückmeldungen'],
        ['index' => 'status', 'label' => 'Status'],
        ['index' => 'action', 'label' => ''],
    ]" :rows="$allocations" :sort="[]">
        @interact('column_day', $allocation)
            <a class="font-semibold text-gray-900 hover:text-indigo-600" href="{{ route('allocations.show', $allocation) }}">{{ $allocation->emergencyDay->date->translatedFormat('l, d. F Y') }}</a>
            <p class="mt-1 text-xs text-gray-500">{{ $allocation->emergencyDay->group->name }} · {{ $allocation->emergencyDay->kitaYear->name }} · Verfahren #{{ $allocation->id }}{{ $allocation->previous_allocation_id ? ' · Folgeverfahren' : '' }}</p>
        @endinteract
        @interact('column_responses', $allocation)
            <span class="font-medium tabular-nums">{{ $allocation->answered_count }} / {{ $allocation->responses_count }}</span>
            <div class="mt-2 h-1.5 w-24 overflow-hidden rounded-full bg-gray-100" aria-hidden="true"><div class="h-full rounded-full bg-indigo-500" style="width: {{ $allocation->responses_count ? round($allocation->answered_count / $allocation->responses_count * 100) : 0 }}%"></div></div>
        @endinteract
        @interact('column_status', $allocation)
            <x-badge :color="match ($allocation->status) { 'responses' => 'amber', 'proposed' => 'violet', 'confirmed' => 'gray', default => 'indigo' }" light>
                {{ ['responses' => 'Rückmeldungen offen', 'closed' => 'Bereit zur Berechnung', 'proposed' => 'Vorschlag prüfen', 'confirmed' => 'Abgeschlossen'][$allocation->status] }}
            </x-badge>
        @endinteract
        @interact('column_action', $allocation)
            <x-button :href="route('allocations.show', $allocation)" flat sm text="Öffnen" icon="arrow-right" position="right" />
        @endinteract
        <x-slot:empty>
            <div class="py-10 text-center">
                <span class="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-indigo-50 text-indigo-500"><x-icon icon="calendar-days" class="size-7" /></span>
                <h2>Noch keine Vergaben vorhanden</h2>
                <p class="muted mx-auto max-w-md">Legen Sie zuerst ein Kita-Jahr und die Kinder an. Danach kann die erste Notbetreuung starten.</p>
                <x-button :href="route('years.index')" outline class="mt-6" text="Kita-Jahr anlegen" icon="plus" />
            </div>
        </x-slot:empty>
    </x-table>
    @if($allocations->hasPages())
        <x-slot:footer unwrapped>{{ $allocations->links() }}</x-slot:footer>
    @endif
</x-card>

<div class="mt-8 grid gap-4 lg:grid-cols-2">
    @foreach(['01' => ['Reguläre Ansprüche', 'Der Standardanspruch bleibt ohne Rückmeldung bestehen.'], '02' => ['Warteliste', 'Freie Plätze werden in der bestehenden Reihenfolge vergeben.']] as $step => [$title, $text])
        <div class="flex items-start gap-4 rounded-xl border border-gray-200 p-5">
            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-white text-xs font-semibold text-gray-500 ring-1 ring-gray-200">{{ $step }}</span>
            <div><h2 class="text-sm">{{ $title }}</h2><p class="muted text-xs">{{ $text }}</p></div>
        </div>
    @endforeach
</div>
@endsection
