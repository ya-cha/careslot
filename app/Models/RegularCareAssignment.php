<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RegularCareAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Child $child
 * @property int $weekday
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 */
#[Fillable(['child_id', 'weekday', 'pattern', 'valid_from', 'valid_until'])]
class RegularCareAssignment extends Model
{
    /** @use HasFactory<RegularCareAssignmentFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekday' => 'integer', 'valid_from' => 'immutable_date', 'valid_until' => 'immutable_date'];
    }

    /** @return BelongsTo<Child, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }
}
