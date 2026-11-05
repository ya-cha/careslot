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

<div>
    <div class="panel mb-6 flex flex-wrap items-end gap-4">
        <label class="min-w-48">Gruppe<select wire:model.live="groupId">@foreach($groups as $item)<option value="{{ $item->id }}" wire:key="group-{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></label>
        <label class="min-w-48">Kita-Jahr<select wire:model.live="yearId">@foreach($years as $item)<option value="{{ $item->id }}" wire:key="year-{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></label>
        <label class="min-w-48 grow">Kind suchen<input wire:model.live.debounce.300ms="search" placeholder="Vor- oder Nachname" maxlength="100"></label>
        <span class="text-sm text-stone-500" wire:loading>Liste wird geladen …</span>
    </div>
    <div class="panel overflow-x-auto">
        <div class="mb-5 flex items-center justify-between"><h2>Aktuelle Reihenfolge</h2><span class="badge">{{ $entries->count() }} aktive Einträge</span></div>
        <p class="mb-5 text-sm text-stone-500">Nur bestätigte Nachrückplätze verschieben ein Kind ans Listenende. Angezeigte Positionen beziehen sich auf die vollständige Liste einschließlich ausgeschiedener Kinder.</p>
        <table><thead><tr><th>Position</th><th>Kind</th><th>Status</th><th></th></tr></thead><tbody>
        @forelse($entries as $entry)<tr wire:key="entry-{{ $entry->id }}"><td><span class="position">{{ $entry->position }}</span></td><td class="font-semibold">{{ $entry->child->first_name }} {{ $entry->child->last_name }}</td><td><span class="badge">Aktiv</span></td><td><a class="text-emerald-800" href="{{ route('children.edit', $entry->child) }}">Stammdaten ↗</a></td></tr>
        @empty<tr><td colspan="4" class="py-12 text-center text-stone-500">{{ $year && !$list?->waiting_list_initialized_at ? 'Zuerst Kinder erfassen und unter Kita-Jahre die Grundliste erstellen.' : 'Keine passenden Kinder gefunden.' }}</td></tr>@endforelse
        </tbody></table>
    </div>
</div>
