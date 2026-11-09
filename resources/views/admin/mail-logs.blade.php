@extends('layouts.app')
@section('title', 'E-Mail-Log')
@section('content')
<div class="page-heading"><div><h1>E-Mail-Log</h1><p class="muted">Ausgehende E-Mails mit Versandzeitpunkt und gespeicherter HTML-Ansicht. Nur für die Gesamtverwaltung.</p></div></div>
<x-card bordered shadowless paddingless>
    <x-table :headers="[
        ['index' => 'time', 'label' => 'Versendet am'],
        ['index' => 'sender', 'label' => 'Absender'],
        ['index' => 'recipients', 'label' => 'Empfänger'],
        ['index' => 'subject', 'label' => 'Betreff'],
    ]" :rows="$logs" :sort="[]">
        @interact('column_time', $log)
            <span class="whitespace-nowrap tabular-nums">{{ $log->sent_at->timezone('Europe/Berlin')->format('d.m.Y H:i:s') }}</span>
        @endinteract
        @interact('column_sender', $log)
            @foreach($log->from as $sender)
                <div class="break-all">{{ $sender['address'] }}</div>
            @endforeach
        @endinteract
        @interact('column_recipients', $log)
            @foreach($log->to as $recipient)
                <div class="break-all">{{ $recipient['address'] }}</div>
            @endforeach
        @endinteract
        @interact('column_subject', $log)
            <a href="{{ route('mail-logs.show', $log) }}" class="font-medium text-indigo-600 hover:underline">{{ $log->subject ?: '(Ohne Betreff)' }}</a>
        @endinteract
        <x-slot:empty><div class="py-8 text-center"><x-icon icon="envelope" class="mx-auto mb-3 size-9 text-gray-300" /><p>Noch keine E-Mails protokolliert.</p></div></x-slot:empty>
    </x-table>
    @if($logs->hasPages())
        <x-slot:footer unwrapped>{{ $logs->links() }}</x-slot:footer>
    @endif
</x-card>
@endsection
