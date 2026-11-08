<?php

namespace App\Services;

use App\Models\CareGroup;
use App\Models\Child;
use App\Models\GroupYear;
use App\Models\KitaYear;
use App\Models\User;
use App\Models\WaitingListEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WaitingListService
{
    public function __construct(private AuditService $audit) {}

    public function groupYear(KitaYear $year, int $groupId): GroupYear
    {
        return GroupYear::firstOrCreate(['kita_year_id' => $year->id, 'group_id' => $groupId]);
    }

    public function initialize(KitaYear $year, CareGroup $group): void
    {
        DB::transaction(function () use ($year, $group): void {
            $year = KitaYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            $list = $this->groupYear($year, $group->id);
            if ($list->waiting_list_initialized_at) {
                throw ValidationException::withMessages(['waiting_list' => 'Die Grundliste dieser Gruppe wurde bereits erstellt.']);
            }
            $children = $year->children()->where('group_id', $group->id)->where('active', true)->get()->sortBy(fn ($child) => mb_strtolower($child->last_name.' '.$child->first_name).' '.sprintf('%010d', $child->id));
            foreach ($children->values() as $position => $child) {
                WaitingListEntry::create(['group_id' => $group->id, 'kita_year_id' => $year->id, 'child_id' => $child->id, 'position' => $position + 1]);
            }
            $list->update(['waiting_list_initialized_at' => now(), 'revision' => $list->revision + 1]);
            $this->audit->record('waiting_list.initialized', $list, ['children' => $children->pluck('id')->values()->all()]);
        });
    }

    public function append(Child $child): void
    {
        DB::transaction(function () use ($child): void {
            $year = KitaYear::whereKey($child->kita_year_id)->lockForUpdate()->firstOrFail();
            $list = $this->groupYear($year, $child->group_id);
            if (! $list->waiting_list_initialized_at || ! $child->active || $child->waitingListEntry()->exists()) {
                return;
            }
            WaitingListEntry::create(['group_id' => $child->group_id, 'kita_year_id' => $year->id, 'child_id' => $child->id, 'position' => ((int) $year->waitingListEntries()->where('group_id', $child->group_id)->max('position')) + 1]);
            $list->increment('revision');
            $this->audit->record('waiting_list.appended', $child);
        });
    }

    public function move(WaitingListEntry $entry, int $position, User $administrator): void
    {
        abort_unless($administrator->managesGroup($entry->group_id), 404);

        DB::transaction(function () use ($entry, $position): void {
            $year = KitaYear::whereKey($entry->kita_year_id)->lockForUpdate()->firstOrFail();
            $list = GroupYear::where('kita_year_id', $year->id)->where('group_id', $entry->group_id)->firstOrFail();
            if (! $list->waiting_list_initialized_at) {
                throw ValidationException::withMessages(['position' => 'Zuerst die Grundliste dieser Gruppe erstellen.']);
            }
            $entries = $year->waitingListEntries()->where('group_id', $entry->group_id)->orderBy('position')->lockForUpdate()->get();
            abort_unless($entries->contains('id', $entry->id), 404);
            if ($position < 1 || $position > $entries->count()) {
                throw ValidationException::withMessages(['position' => 'Die Position muss zwischen 1 und '.$entries->count().' liegen.']);
            }
            $before = $entries->pluck('position', 'child_id')->all();
            $order = $entries->where('id', '!=', $entry->id)->pluck('child_id')->all();
            array_splice($order, $position - 1, 0, [$entry->child_id]);
            $after = collect($order)->mapWithKeys(fn (int $childId, int $index): array => [$childId => $index + 1])->all();
            if ($before === $after) {
                return;
            }
            $offset = (int) $entries->max('position') + $entries->count() + 1;
            foreach ($entries as $item) {
                $item->update(['position' => $item->position + $offset]);
            }
            foreach ($order as $index => $childId) {
                $entries->firstWhere('child_id', $childId)->update(['position' => $index + 1]);
            }
            $list->increment('revision');
            $this->audit->record('waiting_list.reordered', $list, ['before' => $before, 'after' => $after]);
        });
    }
}
