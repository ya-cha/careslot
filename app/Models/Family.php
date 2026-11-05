<?php

namespace App\Models;

use Database\Factories\FamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $group_id
 * @property CareGroup $group
 * @property Collection<int, ParentContact> $contacts
 * @property Collection<int, Child> $children
 */
#[Fillable(['group_id', 'name'])]
class Family extends Model
{
    /** @use HasFactory<FamilyFactory> */
    use HasFactory;

    /** @return HasMany<ParentContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(ParentContact::class);
    }

    /** @return HasMany<Child, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Child::class);
    }

    /** @return BelongsTo<CareGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CareGroup::class, 'group_id');
    }
}
