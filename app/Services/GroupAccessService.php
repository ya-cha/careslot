<?php

namespace App\Services;

use App\Models\CareGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GroupAccessService
{
    public function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_admin, 403);

        return $user;
    }

    public function authorize(int $groupId): void
    {
        abort_unless($this->user()->managesGroup($groupId), 404);
    }

    /** @template T of \Illuminate\Database\Eloquent\Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function scope(Builder $query, string $column = 'group_id'): Builder
    {
        $user = $this->user();

        return $query->when($user->group_id !== null, fn ($query) => $query->where($column, $user->group_id));
    }

    /** @return Collection<int, CareGroup> */
    public function groups(): Collection
    {
        return $this->scope(CareGroup::query(), 'id')->orderBy('name')->get();
    }
}
