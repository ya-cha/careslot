<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmergencyAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property EmergencyDay $emergencyDay
 * @property Collection<int, EmergencyResponse> $responses
 * @property Collection<int, AllocationResult> $results
 * @property EmergencyAllocation $previousAllocation
 * @property int $capacity
 * @property int|null $source_revision
 * @property CarbonImmutable|null $response_deadline
 * @property CarbonImmutable|null $responses_closed_at
 * @property CarbonImmutable|null $calculated_at
 * @property CarbonImmutable|null $confirmed_at
 * @property array<string, mixed>|null $proposal
 */
#[Fillable(['emergency_day_id', 'previous_allocation_id', 'capacity', 'status', 'reason', 'response_deadline', 'responses_closed_at', 'calculated_at', 'confirmed_at', 'confirmed_by', 'source_revision', 'proposal'])]
class EmergencyAllocation extends Model
{
    /** @use HasFactory<EmergencyAllocationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['capacity' => 'integer', 'source_revision' => 'integer', 'response_deadline' => 'immutable_datetime', 'responses_closed_at' => 'immutable_datetime', 'calculated_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'proposal' => 'array'];
    }

    public function proposalHash(): ?string
    {
        return $this->proposal !== null ? hash('sha256', json_encode($this->proposal, JSON_THROW_ON_ERROR)) : null;
    }

    /** @return BelongsTo<EmergencyDay, $this> */
    public function emergencyDay(): BelongsTo
    {
        return $this->belongsTo(EmergencyDay::class);
    }

    /** @return HasMany<EmergencyResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(EmergencyResponse::class);
    }

    /** @return HasMany<AllocationResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(AllocationResult::class);
    }

    /** @return BelongsTo<EmergencyAllocation, $this> */
    public function previousAllocation(): BelongsTo
    {
        return $this->belongsTo(EmergencyAllocation::class);
    }
}
