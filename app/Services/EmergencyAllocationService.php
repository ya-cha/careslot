<?php

namespace App\Services;

use App\Models\EmailDelivery;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyDay;
use App\Models\KitaYear;
use App\Models\LotteryDraw;
use App\Models\User;
use App\Models\WaitingListMovement;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Random\Randomizer;

class EmergencyAllocationService
{
    public function __construct(private RegularEntitlementService $entitlements, private AuditService $audit, private CareCommunicationService $communication, private WaitingListService $waitingList) {}

    public function start(EmergencyDay $day, int $capacity, ?string $deadline = null, ?EmergencyAllocation $previous = null, ?string $reason = null): EmergencyAllocation
    {
        return DB::transaction(function () use ($day, $capacity, $deadline, $previous, $reason): EmergencyAllocation {
            $year = KitaYear::whereKey($day->kita_year_id)->lockForUpdate()->firstOrFail();
            $list = $this->waitingList->groupYear($year, $day->group_id);
            if (! $list->waiting_list_initialized_at) {
                $this->fail('Zuerst die alphabetische Grundliste des Kita-Jahres erstellen.');
            }
            if ($capacity < 0 || $day->date->lt($year->starts_on) || $day->date->gt($year->ends_on) || $day->date->isoWeekday() > 5) {
                $this->fail('Ungültige Kapazität oder Datum außerhalb der Betreuungstage des Kita-Jahres.');
            }
            if ($day->allocations()->where('status', '!=', 'confirmed')->exists()) {
                $this->fail('Für diesen Tag läuft bereits ein Verfahren.');
            }
            $latest = $day->allocations()->latest('id')->first();
            if (($latest && (! $previous || $previous->id !== $latest->id)) || ($previous && ($previous->emergency_day_id !== $day->id || $previous->status !== 'confirmed' || ! $reason))) {
                $this->fail('Ein neues Verfahren muss auf den letzten bestätigten Vorgang verweisen und begründet werden.');
            }
            $allocation = $day->allocations()->create(['capacity' => $capacity, 'previous_allocation_id' => $previous?->id, 'reason' => $reason, 'response_deadline' => $deadline, 'status' => 'responses']);
            foreach ($year->children()->where('group_id', $day->group_id)->where('active', true)->with('assignments')->orderBy('id')->get() as $child) {
                $allocation->responses()->create(['child_id' => $child->id, 'claim_snapshot' => $this->entitlements->evaluate($child, $day)]);
            }
            $this->audit->record('allocation.started', $allocation, ['capacity' => $capacity, 'previous' => $previous?->id, 'reason' => $reason]);

            return $allocation;
        });
    }

    public function updateResponseDeadline(EmergencyAllocation $allocation, ?string $deadline): void
    {
        DB::transaction(function () use ($allocation, $deadline): void {
            $allocation = $this->locked($allocation);
            if ($allocation->status !== 'responses') {
                throw ValidationException::withMessages(['response_deadline' => 'Die Rückmeldefrist kann nur während einer offenen Rückmeldungsphase geändert werden.']);
            }
            $newDeadline = $deadline !== null ? Date::parse($deadline) : null;
            if ($newDeadline !== null && $newDeadline->lte(now())) {
                throw ValidationException::withMessages(['response_deadline' => 'Die Rückmeldefrist muss in der Zukunft liegen.']);
            }
            $before = $allocation->response_deadline?->toIso8601String();
            $allocation->update(['response_deadline' => $newDeadline]);
            foreach (EmailDelivery::where('emergency_allocation_id', $allocation->id)->whereNull('sent_at')->whereIn('kind', ['request', 'reminder'])->get() as $delivery) {
                $payload = $delivery->payload;
                $payload['deadline'] = $allocation->response_deadline?->format('d.m.Y H:i');
                $delivery->update(['payload' => $payload]);
            }
            $this->audit->record('responses.deadline_updated', $allocation, ['before' => $before, 'after' => $allocation->response_deadline?->toIso8601String()]);
        });
    }

    public function close(EmergencyAllocation $allocation, bool $onlyIfExpired = false): void
    {
        DB::transaction(function () use ($allocation, $onlyIfExpired): void {
            $allocation = $this->locked($allocation);
            if ($allocation->status !== 'responses') {
                return;
            }
            if ($onlyIfExpired && ($allocation->response_deadline === null || $allocation->response_deadline->gt(now()))) {
                return;
            }
            $allocation->update(['status' => 'closed', 'responses_closed_at' => now()]);
            $this->audit->record('responses.closed', $allocation, ['missing' => $allocation->responses()->whereNull('responded_at')->count()]);
        });
    }

    /** @return array<string, mixed> */
    public function propose(EmergencyAllocation $allocation): array
    {
        return DB::transaction(function () use ($allocation): array {
            $allocation = $this->locked($allocation);
            if ($allocation->status === 'proposed') {
                return $allocation->proposal;
            }
            if ($allocation->status !== 'closed') {
                $this->fail('Die Rückmeldungsphase muss vor der Berechnung geschlossen werden.');
            }
            $year = $allocation->emergencyDay->kitaYear;
            $list = $this->waitingList->groupYear($year, $allocation->emergencyDay->group_id);
            $entries = $year->waitingListEntries()->where('group_id', $allocation->emergencyDay->group_id)->orderBy('position')->get();
            $positions = $entries->pluck('position', 'child_id');
            $responses = $allocation->responses()->with('child')->orderBy('child_id')->get();
            $rows = [];
            foreach ($responses as $response) {
                $claim = $response->claim_snapshot;
                $active = $response->child->active && $response->child->group_id === $allocation->emergencyDay->group_id;
                if ($active && $claim['status'] === 'ambiguous') {
                    $this->fail('Anspruchsregeln für '.$response->child->first_name.' '.$response->child->last_name.' fehlen oder sind widersprüchlich.');
                }
                $eligible = $claim['status'] === 'full';
                $regular = $active && $eligible;
                $awarded = $regular && $response->regular_needed !== false;
                $rows[$response->child_id] = ['child_id' => $response->child_id, 'name' => $response->child->first_name.' '.$response->child->last_name,
                    'phase' => $awarded ? 1 : null, 'awarded' => $awarded, 'offered' => $regular, 'takes_place' => $awarded,
                    'original_position' => $positions->get($response->child_id), 'new_position' => $positions->get($response->child_id),
                    'reason' => ! $active ? 'Kind ist ausgeschieden oder hat die Gruppe gewechselt.' : ($awarded ? ($response->responded_at ? 'Regulärer Platz benötigt.' : 'Keine Rückmeldung: regulärer Standardanspruch bleibt bestehen.') : ($regular ? 'Regulären Platz freigegeben; Wartelistenposition bleibt erhalten.' : 'Kein regulärer Platz.')),
                    'snapshot' => ['claim' => $claim, 'regular' => $regular, 'response' => ['regular_needed' => $response->regular_needed, 'waiting_needed' => $response->waiting_needed, 'volunteer' => $response->volunteer, 'responded_at' => $response->responded_at?->toIso8601String()]]];
            }
            $remaining = $allocation->capacity - count(array_filter($rows, fn ($row) => $row['awarded']));
            if ($remaining < 0) {
                $this->fail('Die benötigten regulären Ansprüche überschreiten die Kapazität. Keine automatische Kürzung möglich.');
            }
            $moved = [];
            foreach ($entries as $entry) {
                $id = $entry->child_id;
                if (! isset($rows[$id]) || ! $responses->firstWhere('child_id', $id)->child->active || $rows[$id]['snapshot']['regular']) {
                    continue;
                }
                $needs = $rows[$id]['snapshot']['response']['waiting_needed'];
                $rows[$id]['offered'] = $remaining > 0;
                $rows[$id]['reason'] = ! $needs ? 'Kein Nachrückbedarf gemeldet; Position bleibt erhalten.' : 'Kapazität durch vorrangige Kinder ausgeschöpft.';
                if ($remaining > 0 && $needs) {
                    $rows[$id] = array_replace($rows[$id], ['awarded' => true, 'takes_place' => true, 'phase' => 2, 'reason' => 'Nachrückplatz nach Wartelistenreihenfolge; Verschiebung erst bei Bestätigung.']);
                    $remaining--;
                    $moved[] = $id;
                }
            }
            $candidates = [];
            if ($remaining > 0) {
                foreach ($responses as $response) {
                    if ($response->child->active && $response->child->group_id === $allocation->emergencyDay->group_id && ! $rows[$response->child_id]['awarded'] && $response->volunteer) {
                        $candidates[] = $response->child_id;
                    }
                }
            }
            $availableLottery = $remaining;
            $winners = count($candidates) > $remaining ? array_slice((new Randomizer)->shuffleArray($candidates), 0, $remaining) : $candidates;
            foreach ($winners as $id) {
                $rows[$id] = array_replace($rows[$id], ['awarded' => true, 'takes_place' => true, 'offered' => true, 'phase' => 3, 'reason' => 'Freiwilliger Platz aus Phase 3; keine Wartelistenverschiebung.']);
                $remaining--;
            }
            foreach (array_diff($candidates, $winners) as $id) {
                $rows[$id]['reason'] = 'Im Losverfahren nicht ausgelost; Wartelistenposition bleibt erhalten.';
            }
            $order = array_merge($entries->pluck('child_id')->reject(fn ($id) => in_array($id, $moved, true))->values()->all(), $moved);
            foreach ($order as $index => $id) {
                if ($moved !== [] && isset($rows[$id])) {
                    $rows[$id]['new_position'] = $index + 1;
                }
            }
            $proposal = ['capacity' => $allocation->capacity, 'remaining' => $remaining, 'rows' => array_values($rows), 'waiting_order' => $order, 'moved' => $moved, 'lottery' => ['candidates' => $candidates, 'winners' => $winners, 'losers' => array_values(array_diff($candidates, $winners)), 'available_places' => $availableLottery, 'drawn_at' => now()->toIso8601String()]];
            $allocation->update(['status' => 'proposed', 'source_revision' => $list->revision, 'calculated_at' => now(), 'proposal' => $proposal]);
            if ($availableLottery > 0) {
                LotteryDraw::create(['emergency_allocation_id' => $allocation->id, ...$proposal['lottery']]);
            }
            $this->audit->record('allocation.proposed', $allocation, ['remaining' => $remaining, 'moved' => $moved]);

            return $proposal;
        });
    }

    public function confirm(EmergencyAllocation $allocation, User $administrator, ?string $expectedProposalHash = null): void
    {
        abort_unless($administrator->managesGroup($allocation->emergencyDay->group_id), 403);
        DB::transaction(function () use ($allocation, $administrator, $expectedProposalHash): void {
            $allocation = $this->locked($allocation);
            if ($allocation->status === 'confirmed') {
                return;
            }
            if ($allocation->status !== 'proposed') {
                $this->fail('Es liegt kein bestätigbarer Vorschlag vor.');
            }
            if ($expectedProposalHash !== null && ! hash_equals($allocation->proposalHash() ?? '', $expectedProposalHash)) {
                $this->fail('Der Vorschlag hat sich geändert. Bitte den aktuellen Vorschlag erneut prüfen und bestätigen.');
            }
            $year = $allocation->emergencyDay->kitaYear;
            $list = $this->waitingList->groupYear($year, $allocation->emergencyDay->group_id);
            if ($list->revision !== $allocation->source_revision) {
                $this->fail('Stammdaten oder Warteliste haben sich geändert. Vorschlag verwerfen und neu berechnen.');
            }
            $proposal = $allocation->proposal;
            if (count(array_filter($proposal['rows'], fn ($row) => $row['awarded'])) > $allocation->capacity) {
                $this->fail('Die Kapazität darf nicht überschritten werden.');
            }
            $entries = $year->waitingListEntries()->where('group_id', $allocation->emergencyDay->group_id)->orderBy('position')->lockForUpdate()->get();
            if ($proposal['moved']) {
                $offset = (int) $entries->max('position') + $entries->count() + 1;
                foreach ($entries as $entry) {
                    $entry->update(['position' => $entry->position + $offset]);
                }
                foreach ($proposal['waiting_order'] as $index => $id) {
                    $entry = $entries->firstWhere('child_id', $id);
                    $entry->update(['position' => $index + 1]);
                }
                $list->increment('revision');
            }
            foreach ($proposal['rows'] as $row) {
                $result = $row;
                unset($result['name']);
                $allocation->results()->create($result);
                if ($row['original_position'] !== $row['new_position']) {
                    WaitingListMovement::create(['emergency_allocation_id' => $allocation->id, 'child_id' => $row['child_id'], 'original_position' => $row['original_position'], 'new_position' => $row['new_position'], 'reason' => in_array($row['child_id'], $proposal['moved'], true) ? 'Nachrückplatz in Anspruch genommen.' : 'Positionsänderung durch Nachrücken anderer Kinder.']);
                }
            }
            $allocation->update(['status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by' => $administrator->id]);
            $allocation->emergencyDay->update(['status' => 'completed']);
            $this->communication->queueResults($allocation);
            $this->audit->record('allocation.confirmed', $allocation, ['proposal' => $proposal]);
        }, 3);
    }

    public function discard(EmergencyAllocation $allocation, ?string $reason = null): void
    {
        DB::transaction(function () use ($allocation, $reason): void {
            $allocation = $this->locked($allocation);
            if ($allocation->status !== 'proposed') {
                $this->fail('Nur ein unverbindlicher Vorschlag kann verworfen werden.');
            }
            $this->audit->record('allocation.proposal_discarded', $allocation, ['proposal' => $allocation->proposal, 'reason' => $reason]);
            $allocation->lotteryDraw()->delete();
            $allocation->update(['status' => 'closed', 'proposal' => null, 'source_revision' => null, 'calculated_at' => null]);
        });
    }

    public function locked(EmergencyAllocation $allocation): EmergencyAllocation
    {
        KitaYear::whereKey($allocation->emergencyDay->kita_year_id)->lockForUpdate()->firstOrFail();

        return EmergencyAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['allocation' => $message]);
    }
}
