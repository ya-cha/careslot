<?php

use App\Models\CareGroup;
use App\Models\Child;
use App\Models\EmergencyDay;
use App\Models\GroupYear;
use App\Models\KitaYear;
use App\Models\RegularCareAssignment;
use App\Models\User;
use App\Services\EmergencyAllocationService;
use App\Services\RegularEntitlementService;
use App\Services\WaitingListService;
use Illuminate\Validation\ValidationException;

it('evaluates the same configurable entitlement for all weekdays', function (int $weekday, string $date) {
    $child = Child::factory()->create();
    RegularCareAssignment::factory()->for($child)->create(['weekday' => $weekday]);
    $day = EmergencyDay::factory()->for($child->kitaYear)->for($child->group, 'group')->create(['date' => $date]);

    $claim = app(RegularEntitlementService::class)->evaluate($child, $day);

    expect($claim['status'])->toBe('full');
})->with([[1, '2026-10-26'], [2, '2026-10-27'], [3, '2026-10-28'], [4, '2026-10-29'], [5, '2026-10-30']]);

it('uses ISO calendar parity including the year boundary', function (string $date, string $pattern, string $expected) {
    $child = Child::factory()->create();
    RegularCareAssignment::factory()->for($child)->create(['weekday' => 5, 'pattern' => $pattern]);
    $day = EmergencyDay::factory()->for($child->kitaYear)->for($child->group, 'group')->create(['date' => $date]);

    expect(app(RegularEntitlementService::class)->evaluate($child, $day)['status'])->toBe($expected);
})->with([['2026-10-30', 'even', 'full'], ['2026-10-30', 'odd', 'none'], ['2027-01-01', 'odd', 'full'], ['2027-01-01', 'even', 'none'], ['2026-10-30', 'never', 'none']]);

it('evaluates daily entitlement independently of the offered care hours', function (?string $dayStart, ?string $dayEnd) {
    $child = Child::factory()->create();
    RegularCareAssignment::factory()->for($child)->create();
    $day = EmergencyDay::factory()->for($child->kitaYear)->for($child->group, 'group')->create(['starts_at' => $dayStart, 'ends_at' => $dayEnd]);

    expect(app(RegularEntitlementService::class)->evaluate($child, $day)['status'])->toBe('full');
})->with([['08:00', '16:00'], ['12:30', '16:00'], [null, null]]);

it('requires clarification for overlapping missing and expired rules', function (string $scenario) {
    $child = Child::factory()->create();
    if ($scenario !== 'missing') {
        RegularCareAssignment::factory()->for($child)->count($scenario === 'overlap' ? 2 : 1)->create(['valid_until' => $scenario === 'expired' ? '2026-09-30' : null]);
    }
    $day = EmergencyDay::factory()->for($child->kitaYear)->for($child->group, 'group')->create();

    expect(app(RegularEntitlementService::class)->evaluate($child, $day)['status'])->toBe('ambiguous');
})->with(['missing', 'expired', 'overlap']);

it('preserves unanswered regular claims and frees only explicitly declined places', function () {
    [$year, $children, $allocation] = careScenario(['always', 'always', 'never'], 2);
    $allocation->responses()->where('child_id', $children[1]->id)->update(['regular_needed' => false, 'waiting_needed' => true, 'responded_at' => now()]);
    $allocation->responses()->where('child_id', $children[2]->id)->update(['waiting_needed' => true, 'responded_at' => now()]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);

    $proposal = $service->propose($allocation);

    expect(array_column($proposal['rows'], 'phase'))->toBe([1, null, 2]);
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseCount('allocation_results', 0);
    $this->assertDatabaseCount('waiting_list_movements', 0);
});

it('moves only the accepted waiting child at confirmation and confirms idempotently', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never', 'never', 'never']);
    $allocation->responses()->where('child_id', $children[1]->id)->update(['waiting_needed' => true]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $proposal = $service->propose($allocation);
    expect($proposal['waiting_order'])->toBe([$children[0]->id, $children[2]->id, $children[3]->id, $children[1]->id]);
    $admin = User::factory()->administrator()->create();

    $service->confirm($allocation, $admin);
    $service->confirm($allocation, $admin);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe([$children[0]->id, $children[2]->id, $children[3]->id, $children[1]->id]);
    expect($allocation->fresh()->status)->toBe('confirmed');
    $this->assertDatabaseCount('allocation_results', 4);
    $this->assertDatabaseCount('waiting_list_movements', 3);
});

it('leaves the waiting list unchanged when no one needs a place', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $proposal = $service->propose($allocation);
    $service->confirm($allocation, User::factory()->administrator()->create());

    expect($proposal['remaining'])->toBe(1);
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseCount('waiting_list_movements', 0);
});

it('draws exactly the remaining places without moving volunteers and preserves the draw', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never', 'never'], 2);
    $allocation->responses()->update(['volunteer' => true, 'responded_at' => now()]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);

    $proposal = $service->propose($allocation);
    $second = $service->propose($allocation);
    $service->confirm($allocation, User::factory()->administrator()->create());

    expect($proposal['lottery']['winners'])->toHaveCount(2);
    expect($proposal['lottery']['losers'])->toHaveCount(1);
    expect($second)->toEqual($proposal);
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseCount('lottery_draws', 1);
    $this->assertDatabaseCount('waiting_list_movements', 0);
});

it('awards all volunteers when fewer apply and keeps unused places free', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never'], 3);
    $allocation->responses()->where('child_id', $children[0]->id)->update(['volunteer' => true]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);

    $proposal = $service->propose($allocation);

    expect($proposal['lottery']['winners'])->toBe([$children[0]->id]);
    expect($proposal['remaining'])->toBe(2);
});

it('blocks calculation before closing responses', function () {
    [$year, $children, $allocation] = careScenario(['always']);

    expect(fn () => app(EmergencyAllocationService::class)->propose($allocation))->toThrow(ValidationException::class, 'Rückmeldungsphase');
});

it('never silently reduces regular claims to fit capacity', function () {
    [$year, $children, $allocation] = careScenario(['always', 'always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);

    expect(fn () => $service->propose($allocation))->toThrow(ValidationException::class, 'Kapazität');
    $this->assertDatabaseCount('allocation_results', 0);
});

it('allocates regular daily entitlement without individual time clarification', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);

    $proposal = $service->propose($allocation);

    expect($proposal['rows'][0]['phase'])->toBe(1);
    expect($proposal['rows'][0]['snapshot']['claim']['rules'][0])->not->toHaveKeys(['time_mode', 'starts_at', 'ends_at']);
});

it('rejects stale proposals after another list or child change', function () {
    [$year, $children, $allocation] = careScenario(['never']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    GroupYear::where('kita_year_id', $year->id)->firstOrFail()->increment('revision');

    expect(fn () => $service->confirm($allocation, User::factory()->administrator()->create()))->toThrow(ValidationException::class, 'geändert');
    $this->assertDatabaseCount('allocation_results', 0);
});

it('keeps historical snapshots when master data changes and starts a separate new procedure', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $service->confirm($allocation, User::factory()->administrator()->create());
    $children[0]->assignments()->update(['pattern' => 'never']);

    $new = $service->start($allocation->emergencyDay, 0, previous: $allocation->fresh(), reason: 'Weniger Personal');

    expect($allocation->results()->first()->snapshot['claim']['rules'][0]['pattern'])->toBe('always');
    expect($new->previous_allocation_id)->toBe($allocation->id);
    expect($new->responses()->first()->claim_snapshot['status'])->toBe('none');
});

it('creates an alphabetical base list once and appends new children', function () {
    $year = KitaYear::factory()->create();
    $group = CareGroup::factory()->create();
    $ben = Child::factory()->for($year)->for($group, 'group')->create(['last_name' => 'Bauer']);
    $clara = Child::factory()->for($year)->for($group, 'group')->create(['last_name' => 'Conrad']);
    $inactive = Child::factory()->for($year)->for($group, 'group')->create(['active' => false]);
    $service = app(WaitingListService::class);
    $service->initialize($year, $group);
    $anna = Child::factory()->for($year)->for($group, 'group')->create(['last_name' => 'Aachen']);

    $service->append($anna);
    $service->append($anna);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe([$ben->id, $clara->id, $anna->id]);
    expect(fn () => $service->initialize($year, $group))->toThrow(ValidationException::class, 'bereits');
});

it('excludes deactivated children from future allocation', function () {
    [$year, $children, $allocation] = careScenario(['always', 'never']);
    $children[0]->update(['active' => false]);
    $allocation->responses()->update(['waiting_needed' => true, 'volunteer' => true]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);

    $proposal = $service->propose($allocation);

    expect($proposal['rows'][0]['awarded'])->toBeFalse();
    expect($proposal['rows'][1]['phase'])->toBe(2);
});

it('rotates multiple accepted waiting children in their original relative order', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never', 'never', 'never'], 2);
    $allocation->responses()->whereIn('child_id', [$children[0]->id, $children[2]->id])->update(['waiting_needed' => true]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);

    $service->confirm($allocation, User::factory()->administrator()->create());

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe([$children[1]->id, $children[3]->id, $children[0]->id, $children[2]->id]);
    expect($allocation->results()->where('phase', 2)->count())->toBe(2);
});

it('invalidates a concurrent proposal when another procedure changes the same waiting list', function () {
    [$year, $children, $first] = careScenario(['never', 'never']);
    $service = app(EmergencyAllocationService::class);
    $secondDay = EmergencyDay::factory()->for($year)->for($first->emergencyDay->group, 'group')->create(['date' => '2026-11-02']);
    $second = $service->start($secondDay, 1);
    $first->responses()->where('child_id', $children[0]->id)->update(['waiting_needed' => true]);
    $service->close($first);
    $service->close($second);
    $service->propose($first);
    $service->propose($second);
    $admin = User::factory()->administrator()->create();
    $service->confirm($first, $admin);

    expect(fn () => $service->confirm($second, $admin))->toThrow(ValidationException::class, 'geändert');

    expect($second->fresh()->status)->toBe('proposed');
    expect($second->results()->count())->toBe(0);
});

it('can discard an outdated proposal without changing the waiting list', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never']);
    $allocation->responses()->update(['volunteer' => true]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);

    $service->discard($allocation);

    expect($allocation->fresh()->status)->toBe('closed');
    expect($allocation->fresh()->proposal)->toBeNull();
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseHas('audit_logs', ['event' => 'allocation.proposal_discarded']);
});
