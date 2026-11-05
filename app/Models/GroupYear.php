<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GroupYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $waiting_list_initialized_at
 * @property int $revision
 */
#[Fillable(['group_id', 'kita_year_id', 'revision', 'waiting_list_initialized_at'])]
class GroupYear extends Model
{
    /** @use HasFactory<GroupYearFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revision' => 'integer', 'waiting_list_initialized_at' => 'immutable_datetime'];
    }
}
