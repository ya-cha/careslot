@extends('layouts.app')
@section('title', 'Historie')
@section('content')
<div class="page-heading"><div><h1>Historie</h1><p class="muted">Änderungen an Stammdaten, Rückmeldungen und Vergaben bleiben dokumentiert.</p></div></div>
<x-card bordered shadowless paddingless>
    <x-table :headers="[
        ['index' => 'time', 'label' => 'Zeitpunkt'],
        ['index' => 'event', 'label' => 'Ereignis'],
        ['index' => 'subject', 'label' => 'Bezug'],
        ['index' => 'actor', 'label' => 'Ausgeführt durch'],
        ['index' => 'details', 'label' => 'Details'],
    ]" :rows="$logs" :sort="[]">
        @interact('column_time', $log)
            <span class="whitespace-nowrap tabular-nums">{{ $log->created_at->format('d.m.Y H:i:s') }}</span>
        @endinteract
        @interact('column_event', $log)
            <span class="font-medium">{{ $log->event }}</span>
        @endinteract
        @interact('column_subject', $log)
            {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
        @endinteract
        @interact('column_actor', $log)
            {{ $log->user_id ? 'Admin #'.$log->user_id : 'Eltern / System' }}
        @endinteract
        @interact('column_details', $log)
            <details>
                <summary class="cursor-pointer font-medium text-indigo-600">Ansehen</summary>
                <pre class="mt-3 max-w-xl overflow-auto rounded-lg bg-gray-50 p-4 text-xs whitespace-pre-wrap">{{ json_encode($log->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        @endinteract
        <x-slot:empty><div class="py-8 text-center"><x-icon icon="clock" class="mx-auto mb-3 size-9 text-gray-300" /><p>Noch keine Änderungen dokumentiert.</p></div></x-slot:empty>
    </x-table>
    @if($logs->hasPages())
        <x-slot:footer unwrapped>{{ $logs->links() }}</x-slot:footer>
    @endif
</x-card>
@endsection
