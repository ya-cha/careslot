<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\EmailDelivery;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyResponse;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Model;

class AuditService
{
    /** @param array<string, mixed> $data */
    public function record(string $event, Model $subject, array $data = []): void
    {
        if (session()->has('impersonator_id')) {
            $data['impersonator_id'] = session('impersonator_id');
        }

        $groupId = match (true) {
            $subject instanceof EmergencyAllocation => $subject->emergencyDay->group_id,
            $subject instanceof EmergencyResponse => $subject->allocation->emergencyDay->group_id,
            $subject instanceof PersonalAccessToken => $subject->response->allocation->emergencyDay->group_id,
            $subject instanceof ParentContact => $subject->family->group_id,
            $subject instanceof EmailDelivery => EmergencyAllocation::findOrFail($subject->emergency_allocation_id)->emergencyDay->group_id,
            $subject instanceof CareGroup => $subject->id,
            default => $subject->getAttribute('group_id'),
        };
        AuditLog::create(['group_id' => $groupId, 'user_id' => auth()->id(), 'event' => $event, 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(), 'data' => $data]);
    }
}
