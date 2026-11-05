<?php

namespace App\Models;

use Database\Factories\CareGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class CareGroup extends Model
{
    /** @use HasFactory<CareGroupFactory> */
    use HasFactory;

    /** @return HasMany<Child, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Child::class, 'group_id');
    }

    /** @return HasMany<User, $this> */
    public function administrators(): HasMany
    {
        return $this->hasMany(User::class, 'group_id')->where('is_admin', true);
    }

    /** @return HasMany<GroupYear, $this> */
    public function groupYears(): HasMany
    {
        return $this->hasMany(GroupYear::class, 'group_id');
    }
}
