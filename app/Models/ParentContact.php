<?php

namespace App\Models;

use Database\Factories\ParentContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Family $family
 */
#[Fillable(['family_id', 'name', 'email'])]
class ParentContact extends Model
{
    /** @use HasFactory<ParentContactFactory> */
    use HasFactory;

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }
}
