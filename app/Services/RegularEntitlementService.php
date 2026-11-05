<?php

namespace App\Services;

use App\Models\Child;
use App\Models\EmergencyDay;

class RegularEntitlementService
{
    /** @return array{status: string, rules: array<int, array<string, mixed>>, explanation: string} */
    public function evaluate(Child $child, EmergencyDay $day): array
    {
        $rules = $child->assignments->filter(fn ($rule) => $rule->weekday === $day->date->isoWeekday()
            && $rule->valid_from->lte($day->date)
            && ($rule->valid_until === null || $rule->valid_until->gte($day->date)));
        $snapshot = ['status' => 'ambiguous', 'rules' => $rules->values()->toArray(), 'explanation' => 'Anspruchsregeln fehlen oder sind widersprüchlich.'];
        if ($rules->count() !== 1) {
            return $snapshot;
        }
        $eligible = match ($rules->first()->pattern) {
            'always' => true, 'never' => false,
            'even' => $day->date->isoWeek % 2 === 0,
            'odd' => $day->date->isoWeek % 2 === 1,
            default => null,
        };
        if ($eligible === null) {
            return $snapshot;
        }

        return array_replace($snapshot, ['status' => $eligible ? 'full' : 'none', 'explanation' => $eligible ? 'Regulärer Anspruch.' : 'Kein regulärer Anspruch.']);
    }
}
