<?php

namespace App\Models;

use Database\Factories\ChildFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $group_id
 * @property CareGroup $group
 * @property KitaYear $kitaYear
 * @property Family $family
 * @property Collection<int, RegularCareAssignment> $assignments
 * @property WaitingListEntry |null $waitingListEntry
 * @property bool $active
 */
#[Fillable(['group_id', 'kita_year_id', 'family_id', 'first_name', 'last_name', 'active'])]
class Child extends Model
{
    /** @use HasFactory<ChildFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<KitaYear, $this> */
    public function kitaYear(): BelongsTo
    {
        return $this->belongsTo(KitaYear::class);
    }

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /** @return HasMany<RegularCareAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RegularCareAssignment::class);
    }

    /** @return HasOne<WaitingListEntry, $this> */
    public function waitingListEntry(): HasOne
    {
        return $this->hasOne(WaitingListEntry::class);
    }

    /** @return BelongsTo<CareGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CareGroup::class, 'group_id');
    }
}
