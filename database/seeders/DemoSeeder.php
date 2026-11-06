<?php

namespace Database\Seeders;

use App\Models\CareGroup;
use App\Models\Child;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyDay;
use App\Models\Family;
use App\Models\KitaYear;
use App\Models\ParentContact;
use App\Models\RegularCareAssignment;
use App\Services\EmergencyAllocationService;
use App\Services\WaitingListService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoSeeder extends Seeder
{
    public function run(WaitingListService $waitingList, EmergencyAllocationService $allocations): void
    {
        if (app()->isProduction()) {
            throw new LogicException('Demodaten dürfen nicht im Produktionsbetrieb angelegt werden.');
        }
        DB::transaction(function () use ($waitingList, $allocations): void {
            if (KitaYear::where('name', 'Demo-Kita')->exists()) {
                return;
            }
            $group = CareGroup::firstOrCreate(['name' => 'Bärengruppe']);
            $year = KitaYear::factory()->create(['name' => 'Demo-Kita', 'starts_on' => now()->startOfYear(), 'ends_on' => now()->addYear()->endOfYear()]);
            foreach (['Anna Bauer', 'Ben Conrad', 'Clara Dietrich', 'David Engel', 'Emma Fischer', 'Felix Graf'] as $index => $name) {
                [$firstName, $lastName] = explode(' ', $name);
                $child = Child::factory()->for($year)->create(['group_id' => $group->id, 'family_id' => Family::factory()->create(['group_id' => $group->id])->id, 'first_name' => $firstName, 'last_name' => $lastName]);
                $child->family->update(['name' => 'Familie '.$lastName]);
                ParentContact::factory()->for($child->family)->create(['name' => 'Eltern '.$lastName, 'email' => 'demo'.($index + 1).'@example.com']);
                foreach (range(1, 5) as $weekday) {
                    $pattern = $index < 2 ? 'always' : 'never';
                    if ($index === 0) {
                        $pattern = match ($weekday) {
                            2 => 'even',
                            3 => 'odd',
                            default => 'always',
                        };
                    }
                    RegularCareAssignment::factory()->for($child)->create(['weekday' => $weekday, 'pattern' => $pattern, 'valid_from' => $year->starts_on]);
                }
            }
            $waitingList->initialize($year, $group);
            $date = now()->next('Monday');
            foreach ([0, 7] as $offset) {
                $day = EmergencyDay::factory()->for($year)->create(['group_id' => $group->id, 'date' => $date->addDays($offset), 'capacity' => 4]);
                $allocation = $allocations->start($day, 4);
                $this->createResponses($allocation);
                if ($offset === 7) {
                    $allocations->close($allocation);
                    $allocations->propose($allocation);
                }
            }
        });
    }

    private function createResponses(EmergencyAllocation $allocation): void
    {
        foreach ($allocation->responses()->orderBy('child_id')->get() as $index => $response) {
            if ($index === 0) {
                continue;
            }
            $response->update(['regular_needed' => $index === 1 ? false : null, 'waiting_needed' => $index === 2, 'responded_at' => now()]);
        }
    }
}
