<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmergencyDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $group_id
 * @property CareGroup $group
 * @property KitaYear $kitaYear
 * @property Collection<int, EmergencyAllocation> $allocations
 * @property CarbonImmutable $date
 * @property int $capacity
 * @property string|null $starts_at
 * @property string|null $ends_at
 */
#[Fillable(['group_id', 'kita_year_id', 'date', 'capacity', 'starts_at', 'ends_at', 'status'])]
class EmergencyDay extends Model
{
    /** @use HasFactory<EmergencyDayFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'capacity' => 'integer'];
    }

    public function careTimeLabel(): string
    {
        $start = $this->starts_at ? substr($this->starts_at, 0, 5) : null;
        $end = $this->ends_at ? substr($this->ends_at, 0, 5) : null;

        return match (true) {
            $start !== null && $end !== null => 'Notbetreuung von '.$start.' bis '.$end.' Uhr',
            $start !== null => 'Notbetreuung ab '.$start.' Uhr',
            $end !== null => 'Notbetreuung bis '.$end.' Uhr',
            default => 'Ganztägige Notbetreuung',
        };
    }

    /** @return BelongsTo<KitaYear, $this> */
    public function kitaYear(): BelongsTo
    {
        return $this->belongsTo(KitaYear::class);
    }

    /** @return HasMany<EmergencyAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(EmergencyAllocation::class);
    }

    /** @return BelongsTo<CareGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CareGroup::class, 'group_id');
    }
}
