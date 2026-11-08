<?php

use App\Models\EmergencyAllocation;
use App\Models\KitaYear;
use App\Models\WaitingListEntry;
use App\Services\GroupAccessService;
use App\Services\WaitingListService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public string $yearId = '';
    public string $groupId = '';
    public string $search = '';
    #[Locked]
    public ?int $editingEntryId = null;
    public string $position = '';
    public bool $positionSaved = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', EmergencyAllocation::class);
        $this->groupId = (string) (app(GroupAccessService::class)->groups()->first()?->id ?? '');
        $this->yearId = (string) (KitaYear::orderByDesc('starts_on')->value('id') ?? '');
    }

    public function updatedGroupId(): void
    {
        $this->cancelEdit();
    }

    public function updatedYearId(): void
    {
        $this->cancelEdit();
    }

    public function editPosition(int $entryId): void
    {
        $entry = $this->selectedEntry($entryId);
        $this->cancelEdit();
        $this->editingEntryId = $entry->id;
        $this->position = (string) WaitingListEntry::where('kita_year_id', $entry->kita_year_id)->where('group_id', $entry->group_id)->where('position', '<=', $entry->position)->count();
    }

    public function savePosition(): void
    {
        $entry = $this->selectedEntry($this->editingEntryId ?? 0);
        $data = $this->validate(['position' => ['required', 'integer', 'min:1']], [
            'position.required' => 'Bitte eine neue Position angeben.',
            'position.integer' => 'Die Position muss eine ganze Zahl sein.',
            'position.min' => 'Die Position muss mindestens 1 sein.',
        ]);
        app(WaitingListService::class)->move($entry, (int) $data['position'], app(GroupAccessService::class)->user());
        $this->cancelEdit();
        $this->positionSaved = true;
    }

    public function cancelEdit(): void
    {
        $this->editingEntryId = null;
        $this->position = '';
        $this->positionSaved = false;
        $this->resetValidation();
    }

    private function selectedEntry(int $entryId): WaitingListEntry
    {
        Gate::authorize('viewAny', EmergencyAllocation::class);
        app(GroupAccessService::class)->authorize((int) $this->groupId);

        return WaitingListEntry::whereKey($entryId)->where('kita_year_id', $this->yearId)->where('group_id', $this->groupId)->firstOrFail();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        Gate::authorize('viewAny', EmergencyAllocation::class);
        $access = app(GroupAccessService::class);
        if ($this->groupId !== '') { $access->authorize((int) $this->groupId); }
        $year = $this->yearId !== '' ? KitaYear::findOrFail($this->yearId) : null;
        $list = $year && $this->groupId !== '' ? \App\Models\GroupYear::where('kita_year_id', $year->id)->where('group_id', $this->groupId)->first() : null;
        $search = mb_substr($this->search, 0, 100);

        return $this->view(['groups' => $access->groups(), 'list' => $list, 'years' => KitaYear::orderByDesc('starts_on')->get(), 'year' => $year, 'totalEntries' => $year && $this->groupId !== '' ? $year->waitingListEntries()->where('group_id', $this->groupId)->count() : 0, 'entries' => $year && $this->groupId !== '' ? $year->waitingListEntries()->where('group_id', $this->groupId)->with('child')->whereHas('child', fn ($query) => $query->where('active', true)->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))))->orderBy('position')->get() : collect()]);
    }
};
?>

<div class="space-y-6">
    @if($positionSaved)
        <div role="status"><x-alert color="green" light title="Reihenfolge gespeichert." /></div>
    @endif
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
            <div><h2>Aktuelle Reihenfolge</h2><p class="muted">Sie können die Positionen jederzeit ändern. Bestätigte Nachrückplätze verschieben ein Kind automatisch ans Listenende.</p></div>
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
            @interact('column_action', $entry, $editingEntryId, $totalEntries)
                @if($editingEntryId === $entry->id)
                    <form wire:submit="savePosition" wire:key="position-editor-{{ $entry->id }}" class="min-w-48 space-y-3">
                        <x-input label="Neue Position" aria-label="Neue Position für {{ $entry->child->first_name }} {{ $entry->child->last_name }}" type="number" min="1" :max="$totalEntries" wire:model="position" required />
                        <div class="flex gap-2">
                            <x-button submit sm text="Speichern" wire:loading.attr="disabled" />
                            <x-button color="gray" flat sm text="Abbrechen" wire:click="cancelEdit" />
                        </div>
                    </form>
                @else
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-button color="gray" outline sm text="Position ändern" wire:click="editPosition({{ $entry->id }})" />
                        <x-button :href="route('children.edit', $entry->child)" flat sm text="Stammdaten" icon="arrow-up-right" position="right" />
                    </div>
                @endif
            @endinteract
            <x-slot:empty>
                <div class="py-8 text-center">
                    <x-icon icon="queue-list" class="mx-auto mb-3 size-9 text-gray-300" />
                    <p class="text-sm text-gray-500">{{ $year && !$list?->waiting_list_initialized_at ? 'Zuerst Kinder erfassen und unter Kita-Jahre die Grundliste erstellen.' : 'Keine passenden Kinder gefunden.' }}</p>
                </div>
            </x-slot:empty>
        </x-table>
        <x-slot:footer unwrapped>
            <p class="text-xs leading-relaxed text-gray-500">Positionen beziehen sich auch bei aktiver Suche auf die vollständige Liste einschließlich ausgeschiedener Kinder. Beim Ändern werden die Positionen fortlaufend nummeriert. Änderungen werden in der Historie protokolliert; bereits berechnete Vergabevorschläge müssen danach neu berechnet werden.</p>
        </x-slot:footer>
    </x-card>
</div>
