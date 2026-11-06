<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmergencyResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property EmergencyAllocation $allocation
 * @property Child $child
 * @property Collection<int, PersonalAccessToken> $tokens
 * @property array<string, mixed> $claim_snapshot
 * @property bool|null $regular_needed
 * @property bool $waiting_needed
 * @property CarbonImmutable|null $responded_at
 */
#[Fillable(['emergency_allocation_id', 'child_id', 'claim_snapshot', 'regular_needed', 'waiting_needed', 'responded_at'])]
class EmergencyResponse extends Model
{
    /** @use HasFactory<EmergencyResponseFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['claim_snapshot' => 'array', 'regular_needed' => 'boolean', 'waiting_needed' => 'boolean', 'responded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EmergencyAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(EmergencyAllocation::class, 'emergency_allocation_id');
    }

    /** @return BelongsTo<Child, $this> */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    /** @return HasMany<PersonalAccessToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }
}
