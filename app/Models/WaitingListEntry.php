<?php

namespace App\Models;

use Database\Factories\WaitingListEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $group_id
 * @property Child $child
 * @property KitaYear $kitaYear
 * @property int $position
 */
#[Fillable(['group_id', 'kita_year_id', 'child_id', 'position'])]
class WaitingListEntry extends Model
{
    /** @use HasFactory<WaitingListEntryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Child, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /** @return BelongsTo<KitaYear, $this> */
    public function kitaYear(): BelongsTo
    {
        return $this->belongsTo(KitaYear::class);
    }
}
