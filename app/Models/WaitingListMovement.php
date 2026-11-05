<?php

namespace App\Models;

use Database\Factories\WaitingListMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Child $child
 */
#[Fillable(['emergency_allocation_id', 'child_id', 'original_position', 'new_position', 'reason'])]
class WaitingListMovement extends Model
{
    /** @use HasFactory<WaitingListMovementFactory> */
    use HasFactory;

    /** @return BelongsTo<Child, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }
}
