@extends('layouts.app')
@section('title', 'Kita-Jahre')
@section('content')
<div class="page-heading"><div><h1>Kita-Jahre</h1><p class="muted">Grundlisten werden für jede Gruppe und jedes Kita-Jahr separat erstellt.</p></div></div>
<div class="grid items-start gap-6 xl:grid-cols-[1fr_360px]">
<div class="space-y-4">@forelse($years as $year)<div class="panel"><div class="flex justify-between gap-3"><div><h2>{{ $year->name }}</h2><p class="muted">{{ $year->starts_on->format('d.m.Y') }} – {{ $year->ends_on->format('d.m.Y') }} · {{ $year->children_count }} Kinder</p></div><span class="badge h-fit">{{ $year->status === 'active' ? 'Aktiv' : 'Archiviert' }}</span></div>
<div class="mt-6 space-y-4">@foreach($groups as $group)
@php($list = $lists->where('kita_year_id', $year->id)->firstWhere('group_id', $group->id))
<div class="flex flex-wrap items-center justify-between gap-3 border-t border-stone-100 pt-4"><span class="text-sm font-medium">{{ $group->name }}</span>@if(!$list?->waiting_list_initialized_at)<form method="POST" action="{{ route('years.initialize', $year) }}">@csrf<input type="hidden" name="group_id" value="{{ $group->id }}"><button class="btn-secondary">Grundliste erstellen</button></form>@else<span class="text-sm text-emerald-800">Grundliste erstellt</span>@endif</div>@endforeach</div>
@can('manage-groups')@if($year->status === 'active')<form class="mt-4" method="POST" action="{{ route('years.archive', $year) }}">@csrf<button class="text-xs text-stone-500 underline">Jahr archivieren</button></form>@endif@endcan
</div>@empty<div class="panel text-stone-500">Noch kein Kita-Jahr vorhanden.</div>@endforelse</div>
@can('manage-groups')<form class="panel space-y-5" method="POST" action="{{ route('years.store') }}">@csrf<h2>Neues Kita-Jahr</h2><label>Bezeichnung<input name="name" placeholder="2026/2027" value="{{ old('name') }}" required maxlength="100"></label><label>Beginn<input type="date" name="starts_on" value="{{ old('starts_on') }}" required></label><label>Ende<input type="date" name="ends_on" value="{{ old('ends_on') }}" required></label><input type="hidden" name="status" value="active"><button class="btn w-full">Kita-Jahr anlegen</button></form>@endcan
</div>
@endsection
