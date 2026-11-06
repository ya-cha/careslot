<?php

use App\Models\EmergencyAllocation;
use App\Models\KitaYear;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public string $yearId = '';
    public string $groupId = '';
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', EmergencyAllocation::class);
        $this->groupId = (string) (app(\App\Services\GroupAccessService::class)->groups()->first()?->id ?? '');
        $this->yearId = (string) (KitaYear::orderByDesc('starts_on')->value('id') ?? '');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        Gate::authorize('viewAny', EmergencyAllocation::class);
        $access = app(\App\Services\GroupAccessService::class);
        if ($this->groupId !== '') { $access->authorize((int) $this->groupId); }
        $year = $this->yearId !== '' ? KitaYear::findOrFail($this->yearId) : null;
        $list = $year && $this->groupId !== '' ? \App\Models\GroupYear::where('kita_year_id', $year->id)->where('group_id', $this->groupId)->first() : null;
        $search = mb_substr($this->search, 0, 100);

        return $this->view(['groups' => $access->groups(), 'list' => $list, 'years' => KitaYear::orderByDesc('starts_on')->get(), 'year' => $year, 'entries' => $year && $this->groupId !== '' ? $year->waitingListEntries()->where('group_id', $this->groupId)->with('child')->whereHas('child', fn ($query) => $query->where('active', true)->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))))->orderBy('position')->get() : collect()]);
    }
};
?>

<div class="space-y-6">
    <x-card bordered shadowless>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_2fr]">
            <x-select.native label="Gruppe" aria-label="Gruppe" wire:model.live="groupId">
                @foreach($groups as $item)
                    <option value="{{ $item->id }}" wire:key="group-{{ $item->id }}">{{ $item->name }}</option>
                @endforeach
            </x-select.native>
            <x-select.native label="Kita-Jahr" aria-label="Kita-Jahr" wire:model.live="yearId">
                @foreach($years as $item)
                    <option value="{{ $item->id }}" wire:key="year-{{ $item->id }}">{{ $item->name }}</option>
                @endforeach
            </x-select.native>
            <div class="sm:col-span-2 xl:col-span-1">
                <x-input label="Kind suchen" icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="Vor- oder Nachname" maxlength="100" />
            </div>
        </div>
    </x-card>
    <x-card bordered shadowless paddingless>
        <x-slot:header>
            <div><h2>Aktuelle Reihenfolge</h2><p class="muted">Nur bestätigte Nachrückplätze verschieben ein Kind ans Listenende.</p></div>
            <x-badge color="indigo" light>{{ $entries->count() }} aktive Einträge</x-badge>
        </x-slot:header>
        <div role="status" wire:loading class="px-6 pt-4 text-sm text-indigo-600">Liste wird geladen …</div>
        <x-table :headers="[
            ['index' => 'position', 'label' => 'Position'],
            ['index' => 'child', 'label' => 'Kind'],
            ['index' => 'status', 'label' => 'Status'],
            ['index' => 'action', 'label' => ''],
        ]" :rows="$entries" :sort="[]" loading>
            @interact('column_position', $entry)
                <span class="position">{{ $entry->position }}</span>
            @endinteract
            @interact('column_child', $entry)
                <span class="font-semibold">{{ $entry->child->first_name }} {{ $entry->child->last_name }}</span>
            @endinteract
            @interact('column_status', $entry)
                <x-badge color="indigo" light text="Aktiv" />
            @endinteract
            @interact('column_action', $entry)
                <x-button :href="route('children.edit', $entry->child)" flat sm text="Stammdaten" icon="arrow-up-right" position="right" />
            @endinteract
            <x-slot:empty>
                <div class="py-8 text-center">
                    <x-icon icon="queue-list" class="mx-auto mb-3 size-9 text-gray-300" />
                    <p class="text-sm text-gray-500">{{ $year && !$list?->waiting_list_initialized_at ? 'Zuerst Kinder erfassen und unter Kita-Jahre die Grundliste erstellen.' : 'Keine passenden Kinder gefunden.' }}</p>
                </div>
            </x-slot:empty>
        </x-table>
        <x-slot:footer unwrapped>
            <p class="text-xs leading-relaxed text-gray-500">Angezeigte Positionen beziehen sich auf die vollständige Liste einschließlich ausgeschiedener Kinder.</p>
        </x-slot:footer>
    </x-card>
</div>
