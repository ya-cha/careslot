<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Übersicht') · careslot</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[#f6f7f3] text-stone-800 antialiased">
@auth
@if(auth()->user()->is_admin && !request()->routeIs('parent.*'))
<div class="lg:grid lg:min-h-screen lg:grid-cols-[238px_1fr]">
    <aside class="flex flex-col border-r border-stone-200 bg-[#edf1e9] p-6 lg:sticky lg:top-0 lg:h-screen">
        <a href="{{ route('dashboard') }}" class="mb-8 flex items-center gap-3 text-2xl font-bold tracking-tight"><span class="grid size-10 place-items-center rounded-xl bg-emerald-900 text-white">c<span class="sr-only">areslot</span></span>careslot<span class="text-emerald-700">.</span></a>
        <p class="mb-4 text-[10px] font-bold tracking-[.18em] text-stone-500">KITA · NOTBETREUUNG</p>
        <nav class="flex flex-wrap gap-1 lg:flex-col" aria-label="Hauptnavigation">
        @foreach(['dashboard' => ['Übersicht', '◫'], 'children.index' => ['Kinder & Familien', '♧'], 'waiting-list' => ['Warteliste', '☷'], 'days.create' => ['Notbetreuung anlegen', '＋'], 'years.index' => ['Kita-Jahre', '▦'], 'history' => ['Historie', '◷']] as $name => [$label, $symbol])
            <a href="{{ route($name) }}" @class(['nav-link', 'bg-white text-emerald-900 shadow-sm' => request()->routeIs($name)])><span class="w-6 text-lg" aria-hidden="true">{{ $symbol }}</span>{{ $label }}</a>
        @endforeach
        @can('manage-groups')<a class="nav-link" href="{{ route('groups.index') }}">Gruppen & Admins</a>@endcan
        </nav>
        <div class="mt-auto flex items-center gap-3 border-t border-stone-300/50 pt-5"><span class="grid size-9 place-items-center rounded-full bg-stone-200 text-xs font-bold">{{ auth()->user()->initials() }}</span><div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p><p class="text-xs text-stone-500">{{ auth()->user()->group?->name ?? 'Gesamtverwaltung' }}</p></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-stone-500" title="Abmelden" aria-label="Abmelden">↪</button></form></div>
    </aside>
    <div class="min-w-0"><header class="flex h-20 items-center justify-between border-b border-stone-200/80 px-6 lg:px-10"><p class="text-sm text-stone-500">Verwaltung <span class="mx-2 text-stone-300">/</span> <span class="text-stone-800">@yield('title', 'Übersicht')</span></p><span class="text-xs text-stone-500">{{ now()->format('d.m.Y') }}</span></header><main class="mx-auto max-w-[1500px] p-6 lg:p-10">@include('layouts.feedback')@yield('content')</main></div>
</div>
@else
<main class="mx-auto max-w-2xl px-5 py-12"><div class="mb-10 text-2xl font-bold text-emerald-900">careslot.</div>@include('layouts.feedback')@yield('content')</main>
@endif
@else
<main class="mx-auto max-w-2xl px-5 py-12"><div class="mb-10 text-2xl font-bold text-emerald-900">careslot.</div>@include('layouts.feedback')@yield('content')</main>
@endauth
@livewireScripts
</body>
</html>
