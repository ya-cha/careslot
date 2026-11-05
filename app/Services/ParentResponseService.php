<?php

namespace App\Services;

use App\Models\EmergencyAllocation;
use App\Models\EmergencyResponse;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParentResponseService
{
    public function __construct(private EmergencyAllocationService $allocations, private AuditService $audit) {}

    public function access(string $plainToken): PersonalAccessToken
    {
        abort_unless(strlen($plainToken) === 64 && ctype_xdigit($plainToken), 404);
        $token = PersonalAccessToken::where('token_hash', hash('sha256', $plainToken))->with('response.allocation.emergencyDay', 'response.child')->firstOrFail();
        abort_if($token->revoked_at || ($token->expires_at && $token->expires_at->lte(now())), 404);

        return $token;
    }

    /** @param array{regular_needed?: bool|null, waiting_needed?: bool, volunteer?: bool} $data */
    public function save(string $plainToken, array $data): EmergencyResponse
    {
        return DB::transaction(function () use ($plainToken, $data): EmergencyResponse {
            $token = $this->access($plainToken);
            $allocation = $this->allocations->locked($token->response->allocation);
            $token = $this->access($plainToken);
            if ($allocation->status !== 'responses' || ($allocation->response_deadline && $allocation->response_deadline->lte(now()))) {
                throw ValidationException::withMessages(['response' => 'Die Rückmeldungsphase ist geschlossen.']);
            }

            return $this->persist($token->response, $allocation, $data, 'response.saved', ['parent_contact_id' => $token->parent_contact_id]);
        });
    }

    /** @param array{regular_needed?: bool|null, waiting_needed?: bool, volunteer?: bool} $data */
    public function saveAsAdministrator(EmergencyResponse $response, array $data, User $administrator): EmergencyResponse
    {
        return DB::transaction(function () use ($response, $data, $administrator): EmergencyResponse {
            abort_unless($administrator->managesGroup($response->allocation->emergencyDay->group_id), 404);
            $allocation = $this->allocations->locked($response->allocation);
            $response->refresh();
            if (! in_array($allocation->status, ['responses', 'closed', 'proposed'], true)) {
                throw ValidationException::withMessages(['response' => 'Die Vergabe ist bereits bestätigt. Änderungen erfordern ein neues Verfahren.']);
            }

            return $this->persist($response, $allocation, $data, 'response.admin_saved', ['source' => 'administrator', 'administrator_id' => $administrator->id, 'allocation_status_before' => $allocation->status, 'after_response_deadline' => $allocation->response_deadline !== null && $allocation->response_deadline->lte(now())], invalidateProposal: $allocation->status === 'proposed');
        });
    }

    /**
     * @param  array{regular_needed?: bool|null, waiting_needed?: bool, volunteer?: bool}  $data
     * @param  array<string, mixed>  $context
     */
    private function persist(EmergencyResponse $response, EmergencyAllocation $allocation, array $data, string $event, array $context, bool $invalidateProposal = false): EmergencyResponse
    {
        abort_unless($response->child->active && $response->child->group_id === $allocation->emergencyDay->group_id, 404);
        $regular = $response->claim_snapshot['status'] === 'full';
        if ($response->claim_snapshot['status'] === 'ambiguous') {
            throw ValidationException::withMessages(['response' => 'Die Anspruchsregeln müssen zuerst durch den Gruppenadmin geprüft werden.']);
        }
        if ($regular && (! array_key_exists('regular_needed', $data) || $data['regular_needed'] === null)) {
            throw ValidationException::withMessages(['regular_needed' => 'Bitte den Bedarf am regulären Platz angeben.']);
        }
        $before = $response->only(['regular_needed', 'waiting_needed', 'volunteer', 'responded_at']);
        $response->update(['regular_needed' => $regular ? $data['regular_needed'] : null, 'waiting_needed' => ! $regular && ($data['waiting_needed'] ?? false), 'volunteer' => $data['volunteer'] ?? false, 'responded_at' => now()]);
        $after = $response->only(['regular_needed', 'waiting_needed', 'volunteer', 'responded_at']);
        $changed = $before['responded_at'] === null || $before['regular_needed'] !== $after['regular_needed'] || $before['waiting_needed'] !== $after['waiting_needed'] || $before['volunteer'] !== $after['volunteer'];
        $proposalInvalidated = $invalidateProposal && $changed;
        if ($proposalInvalidated) {
            $this->allocations->discard($allocation, reason: 'Rückmeldung durch Admin korrigiert.');
        }
        $this->audit->record($event, $response, [...$context, 'proposal_invalidated' => $proposalInvalidated, 'before' => $before, 'after' => $after]);

        return $response;
    }
}
