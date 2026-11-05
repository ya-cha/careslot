<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminResponseRequest;
use App\Http\Requests\ChildRequest;
use App\Http\Requests\EmergencyDayRequest;
use App\Http\Requests\KitaYearRequest;
use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\Child;
use App\Models\EmailDelivery;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyDay;
use App\Models\EmergencyResponse;
use App\Models\Family;
use App\Models\GroupYear;
use App\Models\KitaYear;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Services\AuditService;
use App\Services\CareCommunicationService;
use App\Services\EmergencyAllocationService;
use App\Services\GroupAccessService;
use App\Services\ParentResponseService;
use App\Services\WaitingListService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CareAdminController extends Controller
{
    public function __construct(private EmergencyAllocationService $allocations, private WaitingListService $waitingList, private AuditService $audit, private CareCommunicationService $communication, private GroupAccessService $access) {}

    public function dashboard(): View
    {
        return view('admin.dashboard', ['years' => KitaYear::withCount(['children' => fn ($query) => $query->when($this->access->user()->group_id, fn ($query, $groupId) => $query->where('group_id', $groupId))->where('active', true)])->orderByDesc('starts_on')->get(), 'allocations' => EmergencyAllocation::whereHas('emergencyDay', fn ($query) => $this->access->scope($query))->with('emergencyDay.kitaYear', 'emergencyDay.group')->withCount(['responses', 'responses as answered_count' => fn ($query) => $query->whereNotNull('responded_at')])->orderByDesc('id')->paginate(15), 'childrenCount' => $this->access->scope(Child::query())->where('active', true)->count(), 'pendingCount' => EmergencyResponse::whereHas('allocation.emergencyDay', fn ($query) => $this->access->scope($query))->whereNull('responded_at')->whereHas('allocation', fn ($query) => $query->where('status', 'responses'))->count()]);
    }

    public function years(): View
    {
        return view('admin.years', ['years' => KitaYear::withCount(['children' => fn ($query) => $query->when($this->access->user()->group_id, fn ($query, $groupId) => $query->where('group_id', $groupId))])->orderByDesc('starts_on')->get(), 'groups' => $this->access->groups(), 'lists' => $this->access->scope(GroupYear::query())->get()]);
    }

    public function storeYear(KitaYearRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $year = KitaYear::create($request->validated());
            $this->audit->record('year.created', $year, $year->toArray());
        });

        return back()->with('success', 'Kita-Jahr angelegt. Kinder erfassen und anschließend die Grundliste erstellen.');
    }

    public function archiveYear(KitaYear $year): RedirectResponse
    {
        abort_unless($this->access->user()->isSystemAdministrator(), 403);
        DB::transaction(function () use ($year): void {
            $year = KitaYear::whereKey($year->id)->lockForUpdate()->firstOrFail();
            $year->update(['status' => 'archived']);
            $this->audit->record('year.archived', $year);
        });

        return back()->with('success', 'Kita-Jahr archiviert.');
    }

    public function initialize(Request $request, KitaYear $year): RedirectResponse
    {
        $data = $request->validate(['group_id' => ['required', 'integer', 'exists:care_groups,id']]);
        $this->access->authorize((int) $data['group_id']);
        $this->waitingList->initialize($year, CareGroup::whereKey((int) $data['group_id'])->firstOrFail());

        return back()->with('success', 'Alphabetische Grundliste erstellt. Neue Kinder werden künftig angehängt.');
    }

    public function children(Request $request): View
    {
        $search = mb_substr((string) $request->query('search', ''), 0, 100);

        return view('admin.children', ['children' => $this->access->scope(Child::query())->with('kitaYear', 'group', 'family.contacts', 'waitingListEntry')->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')))->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(25)->withQueryString(), 'search' => $search]);
    }

    public function childForm(?Child $child = null): View
    {
        if ($child) {
            $this->access->authorize($child->group_id);
        }
        $child?->load('assignments', 'family.contacts');

        return view('admin.child-form', ['child' => $child, 'years' => KitaYear::orderByDesc('starts_on')->get(), 'groups' => $this->access->groups(), 'families' => $this->access->scope(Family::query())->with('contacts', 'group')->orderBy('name')->get()]);
    }

    public function storeChild(ChildRequest $request): RedirectResponse
    {
        $child = $this->persistChild($request);

        return redirect()->route('children.edit', $child)->with('success', 'Kind und Ansprüche angelegt.');
    }

    public function updateChild(ChildRequest $request, Child $child): RedirectResponse
    {
        $this->persistChild($request, $child);

        return back()->with('success', 'Stammdaten gespeichert. Bereits gestartete Verfahren behalten ihre Anspruchssnapshots.');
    }

    private function persistChild(ChildRequest $request, ?Child $child = null): Child
    {
        return DB::transaction(function () use ($request, $child): Child {
            $data = $request->validated();
            $year = KitaYear::whereKey($data['kita_year_id'])->lockForUpdate()->firstOrFail();
            $this->access->authorize((int) $data['group_id']);
            if ($child) {
                $this->access->authorize($child->group_id);
            }
            $list = $this->waitingList->groupYear($year, (int) $data['group_id']);
            $before = $child?->load('assignments', 'family.contacts')->toArray();
            $family = ! empty($data['family_id']) ? Family::whereKey((int) $data['family_id'])->where('group_id', $data['group_id'])->firstOrFail() : Family::create(['name' => $data['family_name'], 'group_id' => $data['group_id']]);
            if (isset($data['contacts'])) {
                foreach ($data['contacts'] as $contact) {
                    $family->contacts()->updateOrCreate(['email' => $contact['email']], ['name' => $contact['name']]);
                }
            }
            $attributes = ['group_id' => $data['group_id'], 'kita_year_id' => $year->id, 'family_id' => $family->id, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'active' => $data['active']];
            if ($child) {
                if ($child->group_id !== (int) $data['group_id']) {
                    $before = ['id' => $child->id, 'group_id' => $child->group_id];
                    $this->waitingList->groupYear($year, $child->group_id)->increment('revision');
                    $child->waitingListEntry()->delete();
                    $this->audit->record('child.group_changed', $child, ['from' => $child->group_id, 'to' => (int) $data['group_id']]);
                }
                if ($child->family_id !== $family->id || $child->group_id !== (int) $data['group_id']) {
                    PersonalAccessToken::whereHas('response', fn ($query) => $query->where('child_id', $child->id))->whereNull('revoked_at')->update(['revoked_at' => now()]);
                }
                $child->update($attributes);
            } else {
                $child = Child::create($attributes);
            }
            $child->assignments()->delete();
            foreach ($data['rules'] as $rule) {
                $child->assignments()->create(['weekday' => $rule['weekday'], 'pattern' => $rule['pattern'], 'valid_from' => $rule['valid_from'], 'valid_until' => $rule['valid_until'] ?? null]);
            }
            $list->increment('revision');
            $this->waitingList->append($child);
            $this->audit->record('child.saved', $child, ['before' => $before, 'after' => $child->fresh()->load('assignments', 'family.contacts')->toArray()]);

            return $child;
        });
    }

    public function updateContact(Request $request, ParentContact $contact): RedirectResponse
    {
        $this->access->authorize($contact->family->group_id);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', Rule::unique('parent_contacts')->where('family_id', $contact->family_id)->ignore($contact->id)]]);
        DB::transaction(function () use ($contact, $data): void {
            $before = $contact->only('name', 'email');
            $contact->update($data);
            if ($before['email'] !== $data['email']) {
                PersonalAccessToken::where('parent_contact_id', $contact->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            $this->audit->record('contact.updated', $contact, ['before' => $before, 'after' => $data]);
        });

        return back()->with('success', 'Elternkontakt aktualisiert.');
    }

    public function newDay(): View
    {
        return view('admin.day-form', ['groups' => $this->access->groups(), 'years' => KitaYear::where('status', 'active')->orderByDesc('starts_on')->get()]);
    }

    public function storeDay(EmergencyDayRequest $request): RedirectResponse
    {
        $allocation = DB::transaction(function () use ($request): EmergencyAllocation {
            $data = $request->validated();
            $day = EmergencyDay::create(collect($data)->except('response_deadline')->all());

            return $this->allocations->start($day, (int) $data['capacity'], $data['response_deadline'] ?? null);
        });

        return redirect()->route('allocations.show', $allocation)->with('success', 'Verfahren eröffnet. Rückmeldungsanfragen können jetzt versendet werden.');
    }

    public function show(EmergencyAllocation $allocation): View
    {
        Gate::authorize('update', $allocation);

        return view('admin.allocation', ['allocation' => $allocation->load('emergencyDay.kitaYear', 'responses.child', 'results.child', 'lotteryDraw'), 'deliveries' => EmailDelivery::where('emergency_allocation_id', $allocation->id)->orderByDesc('id')->get(), 'tokens' => PersonalAccessToken::whereHas('response', fn ($query) => $query->where('emergency_allocation_id', $allocation->id))->with('parentContact', 'response.child')->orderByDesc('id')->get()]);
    }

    public function updateResponse(AdminResponseRequest $request, EmergencyResponse $response, ParentResponseService $responses): RedirectResponse
    {
        $responses->saveAsAdministrator($response, $request->validated(), $request->user());

        $message = $response->allocation->refresh()->status === 'closed' ? 'Rückmeldung im Namen der Eltern gespeichert. Bitte den Vergabevorschlag neu berechnen.' : 'Rückmeldung im Namen der Eltern gespeichert.';

        return redirect()->route('allocations.show', $response->emergency_allocation_id)->with('success', $message);
    }

    public function updateDeadline(Request $request, EmergencyAllocation $allocation): RedirectResponse
    {
        Gate::authorize('update', $allocation);
        $data = $request->validate(['response_deadline' => ['present', 'nullable', 'date', 'after:now']], ['response_deadline.after' => 'Die Rückmeldefrist muss in der Zukunft liegen.']);
        $this->allocations->updateResponseDeadline($allocation, $data['response_deadline']);

        return back()->with('success', 'Rückmeldefrist gespeichert. Bestehende Rückmeldungen bleiben erhalten.');
    }

    public function action(Request $request, EmergencyAllocation $allocation): RedirectResponse
    {
        Gate::authorize('update', $allocation);
        $data = $request->validate(['action' => ['required', 'in:close,propose,confirm,discard,request,remind'], 'confirmation' => ['required_if:action,confirm', 'accepted_if:action,confirm'], 'proposal_hash' => ['required_if:action,confirm', 'nullable', 'string', 'size:64']]);
        match ($data['action']) {
            'close' => $this->allocations->close($allocation),
            'propose' => $this->allocations->propose($allocation),
            'confirm' => $this->allocations->confirm($allocation, $request->user(), $data['proposal_hash'] ?? null),
            'discard' => $this->allocations->discard($allocation),
            'request' => $this->communication->requestResponses($allocation),
            'remind' => $this->communication->requestResponses($allocation, true),
            default => abort(422),
        };

        return back()->with('success', 'Aktion erfolgreich gespeichert. Versandaufträge werden durch die Queue verarbeitet.');
    }

    public function restart(Request $request, EmergencyAllocation $allocation): RedirectResponse
    {
        Gate::authorize('update', $allocation);
        $data = $request->validate(['capacity' => ['required', 'integer', 'min:0', 'max:10000'], 'reason' => ['required', 'string', 'max:2000'], 'response_deadline' => ['nullable', 'date', 'after:now']]);
        $new = $this->allocations->start($allocation->emergencyDay, (int) $data['capacity'], $data['response_deadline'] ?? null, $allocation, $data['reason']);

        return redirect()->route('allocations.show', $new)->with('success', 'Neues Verfahren erstellt. Die vorherige Vergabe bleibt erhalten.');
    }

    public function revoke(PersonalAccessToken $token): RedirectResponse
    {
        $this->access->authorize($token->response->allocation->emergencyDay->group_id);
        DB::transaction(function () use ($token): void {
            $this->allocations->locked($token->response->allocation);
            $token->update(['revoked_at' => now()]);
            $this->audit->record('access.revoked', $token, ['parent_contact_id' => $token->parent_contact_id]);
        });

        return back()->with('success', 'Persönlicher Link widerrufen.');
    }

    public function resend(EmailDelivery $delivery): RedirectResponse
    {
        $this->access->authorize(EmergencyAllocation::findOrFail($delivery->emergency_allocation_id)->emergencyDay->group_id);
        DB::transaction(function () use ($delivery): void {
            $this->allocations->locked(EmergencyAllocation::findOrFail($delivery->emergency_allocation_id));
            $copy = EmailDelivery::create(['emergency_allocation_id' => $delivery->emergency_allocation_id, 'parent_contact_id' => $delivery->parent_contact_id, 'deduplication_key' => 'resend:'.bin2hex(random_bytes(16)), 'kind' => $delivery->kind, 'payload' => $delivery->payload]);
            $this->audit->record('mail.resent', $copy, ['original_delivery_id' => $delivery->id]);
        });

        return back()->with('success', 'E-Mail erneut zum Versand vorgemerkt. Für widerrufene Links bitte eine neue Rückmeldungsanfrage versenden.');
    }

    public function history(): View
    {
        return view('admin.history', ['logs' => $this->access->scope(AuditLog::query())->orderByDesc('id')->paginate(30)]);
    }
}
