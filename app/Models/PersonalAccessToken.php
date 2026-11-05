<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PersonalAccessTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property EmergencyResponse $response
 * @property ParentContact $parentContact
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable(['emergency_response_id', 'parent_contact_id', 'token_hash', 'expires_at', 'revoked_at'])]
class PersonalAccessToken extends Model
{
    /** @use HasFactory<PersonalAccessTokenFactory> */
    use HasFactory;

    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<EmergencyResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(EmergencyResponse::class, 'emergency_response_id');
    }

    /** @return BelongsTo<ParentContact, $this> */
    public function parentContact(): BelongsTo
    {
        return $this->belongsTo(ParentContact::class);
    }
}
