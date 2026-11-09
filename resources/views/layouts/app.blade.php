<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Übersicht') · careslot</title>
    <tallstackui:script />
    @livewireStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-100 focus:rounded-lg focus:bg-white focus:p-3">Zum Inhalt springen</a>
@if(auth()->check() && auth()->user()->is_admin && !request()->routeIs('parent.*'))
    <x-layout>
        <x-slot:header>
            <x-layout.header>
                <x-slot:left>
                    <span class="hidden text-sm text-gray-400 sm:inline">Verwaltung</span>
                    <x-icon icon="chevron-right" class="hidden size-3 text-gray-300 sm:inline" />
                    <span class="text-sm font-medium">@yield('title', 'Übersicht')</span>
                </x-slot:left>
                <x-slot:right>
                    <span class="text-xs text-gray-500">{{ now()->translatedFormat('D, d. M Y') }}</span>
                </x-slot:right>
            </x-layout.header>
        </x-slot:header>
        <x-slot:menu>
            <x-side-bar thin-scroll>
                <x-slot:brand>
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-6 pt-7 pb-8" aria-label="careslot – Übersicht">
                        <span class="grid size-10 place-items-center rounded-xl bg-indigo-600 text-white"><x-icon icon="squares-2x2" class="size-6" /></span>
                        <span class="text-xl font-bold tracking-tight">careslot<span class="text-indigo-600">.</span></span>
                    </a>
                    <p class="px-5 pb-3 text-[10px] font-semibold tracking-[0.16em] text-gray-400 uppercase">Arbeitsbereich</p>
                </x-slot:brand>
                <x-side-bar.item text="Übersicht" icon="squares-2x2" :route="route('dashboard')" :current="request()->routeIs('dashboard', 'allocations.*')" />
                <x-side-bar.item text="Kinder & Familien" icon="users" :route="route('children.index')" :current="request()->routeIs('children.*')" />
                <x-side-bar.item text="Warteliste" icon="queue-list" :route="route('waiting-list')" :current="request()->routeIs('waiting-list')" />
                <x-side-bar.item text="Notbetreuung anlegen" icon="calendar-days" :route="route('days.create')" :current="request()->routeIs('days.*')" />
                <x-side-bar.separator text="Organisation" />
                <x-side-bar.item text="Kita-Jahre" icon="calendar" :route="route('years.index')" :current="request()->routeIs('years.*')" />
                @can('manage-groups')
                    <x-side-bar.item text="Gruppen & Admins" icon="building-office-2" :route="route('groups.index')" :current="request()->routeIs('groups.*', 'users.*')" />
                @endcan
                <x-side-bar.item text="Historie" icon="clock" :route="route('history')" :current="request()->routeIs('history')" />
                @can('view-mail-logs')
                    <x-side-bar.item text="E-Mail-Log" icon="envelope" :route="route('mail-logs.index')" :current="request()->routeIs('mail-logs.*')" />
                @endcan
                <x-slot:footer>
                    <div class="flex items-center gap-3 px-3 py-2">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-700">{{ auth()->user()->initials() }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-gray-500">{{ auth()->user()->group?->name ?? 'Gesamtverwaltung' }}</p>
                        </div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-button.circle submit icon="arrow-right-start-on-rectangle" color="gray" flat title="Abmelden" aria-label="Abmelden" />
                        </form>
                    </div>
                </x-slot:footer>
            </x-side-bar>
        </x-slot:menu>
        <div id="main-content" class="mx-auto max-w-[1440px] min-w-0">
            @include('layouts.impersonation')
            @include('layouts.feedback')
            @yield('content')
        </div>
    </x-layout>
@else
    <div class="min-h-dvh bg-gray-50">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-5 py-5 sm:px-8">
                <div class="flex items-center gap-2.5"><span class="grid size-8 place-items-center rounded-lg bg-indigo-600 text-white"><x-icon icon="squares-2x2" class="size-5" /></span><span class="text-lg font-bold tracking-tight">careslot<span class="text-indigo-600">.</span></span></div>
                <span class="text-xs text-gray-500">{{ request()->routeIs('parent.*') ? 'Elternrückmeldung' : 'Kita-Verwaltung' }}</span>
            </div>
        </header>
        <main id="main-content" class="mx-auto max-w-2xl px-5 py-10 sm:py-16">
            @include('layouts.impersonation')
            @include('layouts.feedback')
            @yield('content')
        </main>
    </div>
@endif
@livewireScripts
</body>
</html>
