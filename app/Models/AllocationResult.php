<?php

namespace App\Models;

use Database\Factories\AllocationResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Child $child
 * @property EmergencyAllocation $allocation
 * @property array<string, mixed> $snapshot
 * @property bool $awarded
 * @property bool $offered
 * @property bool $takes_place
 * @property int $phase
 */
#[Fillable(['emergency_allocation_id', 'child_id', 'phase', 'awarded', 'offered', 'takes_place', 'original_position', 'new_position', 'reason', 'snapshot'])]
class AllocationResult extends Model
{
    /** @use HasFactory<AllocationResultFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'awarded' => 'boolean', 'offered' => 'boolean', 'takes_place' => 'boolean', 'phase' => 'integer'];
    }

    /** @return BelongsTo<Child, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /** @return BelongsTo<EmergencyAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(EmergencyAllocation::class, 'emergency_allocation_id');
    }
}
