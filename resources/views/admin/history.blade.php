@extends('layouts.app')
@section('title', 'Historie')
@section('content')
<div class="page-heading"><div><h1>Historie</h1><p class="muted">Änderungen an Stammdaten, Rückmeldungen und Vergaben bleiben dokumentiert.</p></div></div>
<div class="panel overflow-x-auto"><table><thead><tr><th>Zeitpunkt</th><th>Ereignis</th><th>Bezug</th><th>Ausgeführt durch</th><th>Details</th></tr></thead><tbody>@forelse($logs as $log)<tr><td class="whitespace-nowrap">{{ $log->created_at->format('d.m.Y H:i:s') }}</td><td>{{ $log->event }}</td><td>{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</td><td>{{ $log->user_id ? 'Admin #'.$log->user_id : 'Eltern / System' }}</td><td><details><summary class="cursor-pointer text-emerald-800">Ansehen</summary><pre class="mt-3 max-w-xl overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($log->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>@empty<tr><td colspan="5" class="py-12 text-center text-stone-500">Noch keine Änderungen dokumentiert.</td></tr>@endforelse</tbody></table><div class="mt-5">{{ $logs->links() }}</div></div>
@endsection
