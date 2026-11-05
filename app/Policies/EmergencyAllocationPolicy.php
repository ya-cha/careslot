<?php

namespace App\Policies;

use App\Models\EmergencyAllocation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmergencyAllocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, EmergencyAllocation $allocation): Response
    {
        return $user->managesGroup($allocation->emergencyDay->group_id) ? Response::allow() : Response::denyAsNotFound();
    }
}
