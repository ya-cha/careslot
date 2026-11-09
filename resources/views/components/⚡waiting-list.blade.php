<?php

use App\Models\EmergencyAllocation;
use App\Models\KitaYear;
use App\Models\WaitingListEntry;
use App\Services\GroupAccessService;
use App\Services\WaitingListService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    public function sortEntry(int $entryId, int $position): void
    {
        $entry = $this->selectedEntry($entryId);
        $this->cancelEdit();

        DB::transaction(function () use ($entry, $position): void {
            KitaYear::whereKey($entry->kita_year_id)->lockForUpdate()->firstOrFail();
            $visibleEntries = $this->entriesQuery()->get();
            abort_unless($visibleEntries->contains('id', $entry->id), 404);
            if ($position < 0 || $position >= $visibleEntries->count()) {
                throw ValidationException::withMessages(['position' => 'Die Zielposition ist nicht mehr verfügbar. Bitte erneut versuchen.']);
            }

            $target = $visibleEntries[$position];
            $fullPosition = WaitingListEntry::where('kita_year_id', $entry->kita_year_id)
                ->where('group_id', $entry->group_id)->where('position', '<=', $target->position)->count();
            app(WaitingListService::class)->move($entry, $fullPosition, app(GroupAccessService::class)->user());
        });

        $this->positionSaved = true;
    }

    /** @return Builder<WaitingListEntry> */
    private function entriesQuery(): Builder
    {
        $search = mb_substr($this->search, 0, 100);

        return WaitingListEntry::where('kita_year_id', $this->yearId)->where('group_id', $this->groupId)
            ->with('child')->whereHas('child', fn ($query) => $query->where('active', true)
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))))
            ->orderBy('position');
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

        return $this->view(['groups' => $access->groups(), 'list' => $list, 'years' => KitaYear::orderByDesc('starts_on')->get(), 'year' => $year, 'totalEntries' => $year && $this->groupId !== '' ? $year->waitingListEntries()->where('group_id', $this->groupId)->count() : 0, 'entries' => $year && $this->groupId !== '' ? $this->entriesQuery()->get() : collect()]);
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
            <div><h2>Aktuelle Reihenfolge</h2><p class="muted">Ziehen Sie ein Kind am Griff auf die gewünschte Position. Die Reihenfolge wird sofort gespeichert. Zum Eingeben einer Position klicken Sie auf den Griff. Bestätigte Nachrückplätze verschieben ein Kind automatisch ans Listenende.</p></div>
            <x-badge color="indigo" light>{{ $entries->count() }} aktive Einträge</x-badge>
        </x-slot:header>
        <div role="status" wire:loading class="px-6 pt-4 text-sm text-indigo-600">Liste wird geladen …</div>
        @error('position')
            <p role="alert" class="px-6 pt-4 text-sm text-red-600">{{ $message }}</p>
        @enderror
        <div class="overflow-x-auto" wire:loading.class="pointer-events-none opacity-50" wire:loading.attr="inert">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col"><span class="sr-only">Reihenfolge ändern</span></th>
                        <th scope="col">Position</th>
                        <th scope="col">Kind</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="sr-only">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody wire:sort="sortEntry" wire:key="waiting-list-{{ $groupId }}-{{ $yearId }}-{{ md5($search) }}">
                    @forelse($entries as $entry)
                        <tr wire:sort:item="{{ $entry->id }}" wire:key="waiting-entry-{{ $entry->id }}">
                            <td class="w-12">
                                <button type="button" wire:sort:handle wire:click="editPosition({{ $entry->id }})" wire:loading.attr="disabled"
                                    class="inline-flex size-9 touch-none cursor-grab items-center justify-center rounded-lg text-gray-400 hover:bg-indigo-50 hover:text-indigo-700 active:cursor-grabbing"
                                    aria-label="Position für {{ $entry->child->first_name }} {{ $entry->child->last_name }} ändern" title="Ziehen zum Verschieben, klicken zur Positionseingabe">
                                    <x-icon icon="bars-3" class="size-5" />
                                </button>
                            </td>
                            <td><span class="position">{{ $entry->position }}</span></td>
                            <td><span class="font-semibold">{{ $entry->child->first_name }} {{ $entry->child->last_name }}</span></td>
                            <td><x-badge color="indigo" light text="Aktiv" /></td>
                            <td wire:sort:ignore>
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
                                        <x-button :href="route('children.edit', $entry->child)" flat sm text="Stammdaten" icon="arrow-up-right" position="right" />
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">
                            <div class="py-8 text-center">
                                <x-icon icon="queue-list" class="mx-auto mb-3 size-9 text-gray-300" />
                                <p class="text-sm text-gray-500">{{ $year && !$list?->waiting_list_initialized_at ? 'Zuerst Kinder erfassen und unter Kita-Jahre die Grundliste erstellen.' : 'Keine passenden Kinder gefunden.' }}</p>
                            </div>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer unwrapped>
            <p class="text-xs leading-relaxed text-gray-500">Positionen beziehen sich auch bei aktiver Suche auf die vollständige Liste einschließlich ausgeschiedener Kinder. Beim Ändern werden die Positionen fortlaufend nummeriert. Änderungen werden in der Historie protokolliert; bereits berechnete Vergabevorschläge müssen danach neu berechnet werden.</p>
        </x-slot:footer>
    </x-card>
</div>
