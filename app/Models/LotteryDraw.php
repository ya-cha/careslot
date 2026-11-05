<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LotteryDrawFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $candidates
 * @property array<string, mixed> $winners
 * @property array<string, mixed> $losers
 * @property CarbonImmutable $drawn_at
 */
#[Fillable(['emergency_allocation_id', 'available_places', 'candidates', 'winners', 'losers', 'drawn_at'])]
class LotteryDraw extends Model
{
    /** @use HasFactory<LotteryDrawFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['candidates' => 'array', 'winners' => 'array', 'losers' => 'array', 'drawn_at' => 'immutable_datetime'];
    }
}
