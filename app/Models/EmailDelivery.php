<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EmailDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $sent_at
 */
#[Fillable(['emergency_allocation_id', 'parent_contact_id', 'deduplication_key', 'kind', 'payload', 'attempts', 'sent_at', 'last_error'])]
class EmailDelivery extends Model
{
    /** @use HasFactory<EmailDeliveryFactory> */
    use HasFactory;

    protected $hidden = ['payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'sent_at' => 'immutable_datetime'];
    }
}
