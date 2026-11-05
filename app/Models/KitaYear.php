<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\KitaYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Collection<int, Child> $children
 * @property Collection<int, WaitingListEntry> $waitingListEntries
 * @property Collection<int, EmergencyDay> $emergencyDays
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $waiting_list_initialized_at
 * @property int $revision
 */
#[Fillable(['name', 'starts_on', 'ends_on', 'status', 'revision', 'waiting_list_initialized_at'])]
class KitaYear extends Model
{
    /** @use HasFactory<KitaYearFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'waiting_list_initialized_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    /** @return HasMany<Child, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Child::class);
    }

    /** @return HasMany<WaitingListEntry, $this> */
    public function waitingListEntries(): HasMany
    {
        return $this->hasMany(WaitingListEntry::class);
    }

    /** @return HasMany<EmergencyDay, $this> */
    public function emergencyDays(): HasMany
    {
        return $this->hasMany(EmergencyDay::class);
    }
}
