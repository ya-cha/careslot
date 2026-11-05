<?php

namespace App\Services;

use App\Models\CareGroup;
use App\Models\Child;
use App\Models\GroupYear;
use App\Models\KitaYear;
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
}
