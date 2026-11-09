<?php

namespace App\Services;

use App\Models\EmailDelivery;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyResponse;
use App\Models\KitaYear;
use App\Models\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CareCommunicationService
{
    public function __construct(private AuditService $audit) {}

    public function requestResponses(EmergencyAllocation $allocation, bool $reminder = false): void
    {
        DB::transaction(function () use ($allocation, $reminder): void {
            KitaYear::whereKey($allocation->emergencyDay->kita_year_id)->lockForUpdate()->firstOrFail();
            $allocation = EmergencyAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
            if ($allocation->status !== 'responses' || ($allocation->response_deadline && $allocation->response_deadline->lte(now()))) {
                throw ValidationException::withMessages(['mail' => 'Rückmeldungsphase bereits geschlossen.']);
            }
            foreach ($allocation->responses()->with('child.family.contacts')->get() as $response) {
                if ($response->child->group_id !== $allocation->emergencyDay->group_id || ! $response->child->active || ($reminder && $response->responded_at)) {
                    continue;
                }
                foreach ($response->child->family->contacts as $contact) {
                    $this->prepare($allocation, $response, $contact->id, $contact->email, $reminder ? 'reminder' : 'request');
                }
            }
            $this->audit->record($reminder ? 'mail.reminders_requested' : 'mail.responses_requested', $allocation);
        });
    }

    public function queueResults(EmergencyAllocation $allocation): void
    {
        $results = $allocation->results()->get()->keyBy('child_id');
        foreach ($allocation->responses()->with('child.family.contacts')->get() as $response) {
            if ($response->child->group_id !== $allocation->emergencyDay->group_id) {
                continue;
            }
            foreach ($response->child->family->contacts as $contact) {
                $this->prepare($allocation, $response, $contact->id, $contact->email, 'result', $results->get($response->child_id)->awarded ?? false);
            }
        }
    }

    private function prepare(EmergencyAllocation $allocation, EmergencyResponse $response, int $contactId, string $email, string $kind, bool $awarded = false): void
    {
        $key = $kind === 'result' ? 'result:'.$allocation->id.':'.$response->id.':'.$contactId : bin2hex(random_bytes(16));
        if (EmailDelivery::where('deduplication_key', $key)->exists()) {
            return;
        }
        $plainToken = bin2hex(random_bytes(32));
        PersonalAccessToken::create(['emergency_response_id' => $response->id, 'parent_contact_id' => $contactId, 'token_hash' => hash('sha256', $plainToken), 'expires_at' => $allocation->emergencyDay->date->endOfDay()->addDays(30)]);
        EmailDelivery::create(['emergency_allocation_id' => $allocation->id, 'parent_contact_id' => $contactId, 'deduplication_key' => $key, 'kind' => $kind, 'payload' => ['email' => $email, 'token' => $plainToken, 'child_name' => $response->child->first_name.' '.$response->child->last_name, 'date' => $allocation->emergencyDay->date->format('d.m.Y'), 'deadline' => $allocation->response_deadline?->format('d.m.Y H:i'), 'awarded' => $awarded, 'care' => $response->claim_snapshot, 'care_time' => $allocation->emergencyDay->careTimeLabel()]]);
    }
}
