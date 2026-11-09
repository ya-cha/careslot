@extends('layouts.app')
@section('title', 'E-Mail ansehen')
@section('content')
<div class="page-heading">
    <div><h1>E-Mail ansehen</h1><p class="muted">Gespeicherte Nachricht vom {{ $mailLog->sent_at->timezone('Europe/Berlin')->format('d.m.Y H:i:s') }} (Europe/Berlin).</p></div>
    <x-button :href="route('mail-logs.index')" text="Zum E-Mail-Log" icon="arrow-left" color="gray" light />
</div>
<div class="space-y-6">
    <x-card header="Nachricht" bordered shadowless>
        <dl class="grid gap-4 sm:grid-cols-[7rem_1fr]">
            <dt class="font-medium">Betreff</dt><dd class="min-w-0 break-words">{{ $mailLog->subject ?: '(Ohne Betreff)' }}</dd>
            @foreach(['from' => 'Von', 'to' => 'An', 'cc' => 'CC', 'bcc' => 'BCC'] as $field => $label)
                @if($mailLog->{$field})
                    <dt class="font-medium">{{ $label }}</dt>
                    <dd class="min-w-0">
                        @foreach($mailLog->{$field} as $recipient)
                            <div class="break-all">{{ $recipient['name'] ? $recipient['name'].' <'.$recipient['address'].'>' : $recipient['address'] }}</div>
                        @endforeach
                    </dd>
                @endif
            @endforeach
        </dl>
    </x-card>
    <x-card header="HTML-Ansicht" bordered shadowless>
        <iframe src="{{ route('mail-logs.content', $mailLog) }}" title="Gespeicherte E-Mail" sandbox referrerpolicy="no-referrer" class="h-[70vh] min-h-96 w-full rounded-lg border border-gray-200 bg-white"></iframe>
    </x-card>
</div>
@endsection
