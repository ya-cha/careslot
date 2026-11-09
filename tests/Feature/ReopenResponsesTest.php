<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\EmailDelivery;
use App\Models\GroupYear;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\CareCommunicationService;
use App\Services\EmergencyAllocationService;
use App\Services\ParentResponseService;
use Illuminate\Support\Facades\Notification;

it('reopens manually closed expired and proposed response phases preserving answers and list positions', function (string $state, bool $manualDeadline) {
    $this->travelTo('2026-11-05 10:00:00');
    Notification::fake();
    [$year, $children, $allocation] = careScenario(['never', 'never']);
    $allocation->responses()->where('child_id', $children[1]->id)->update(['waiting_needed' => true, 'responded_at' => now()]);
    $service = app(EmergencyAllocationService::class);
    if (in_array($state, ['expired', 'unscheduled'], true)) {
        $allocation->update(['response_deadline' => now()->subMinute()]);
    }
    if ($state !== 'unscheduled') {
        $service->close($allocation);
    }
    if ($state === 'proposed') {
        $service->propose($allocation);
    }
    $beforeResponses = $allocation->responses()->get()->toArray();
    $list = GroupYear::where('kita_year_id', $year->id)->sole();
    $admin = User::factory()->administrator()->for($children[0]->group, 'group')->create();
    $this->actingAs($admin);
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Rückmeldungsphase wieder eröffnen');

    $this->post(route('allocations.responses.reopen', $allocation), ['response_deadline' => $manualDeadline ? '' : '2026-11-06T12:00'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $allocation->refresh();
    expect($allocation->status)->toBe('responses');
    expect($allocation->responses_closed_at)->toBeNull();
    expect($allocation->response_deadline?->format('Y-m-d H:i'))->toBe($manualDeadline ? null : '2026-11-06 12:00');
    expect($allocation->proposal)->toBeNull();
    expect($allocation->source_revision)->toBeNull();
    expect($allocation->calculated_at)->toBeNull();
    expect($allocation->responses()->get()->toArray())->toBe($beforeResponses);
    expect($list->fresh()->revision)->toBe($list->revision);
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $audit = AuditLog::where('event', 'responses.reopened')->sole();
    expect($audit->user_id)->toBe($admin->id);
    expect($audit->group_id)->toBe($children[0]->group_id);
    expect($audit->data['proposal_discarded'])->toBe($state === 'proposed');
    expect(AuditLog::where('event', 'allocation.proposal_discarded')->exists())->toBe($state === 'proposed');
    $this->artisan('careslot:close-responses')->assertSuccessful();
    expect($allocation->fresh()->status)->toBe('responses');
    $this->assertDatabaseCount('allocation_results', 0);
    $this->assertDatabaseCount('waiting_list_movements', 0);
    $this->assertDatabaseCount('email_deliveries', 0);
    $this->assertDatabaseCount('jobs', 0);
    Notification::assertNothingSent();
})->with(['manual', 'expired', 'unscheduled', 'proposed'])->with(['future deadline' => false, 'manual closure' => true]);

it('accepts new and changed parent answers through existing valid links after reopening', function () {
    $this->travelTo('2026-11-05 10:00:00');
    [$year, $children, $allocation] = careScenario(['always', 'never']);
    foreach ($children as $child) {
        ParentContact::factory()->for($child->family)->create();
    }
    app(CareCommunicationService::class)->requestResponses($allocation);
    $deliveries = EmailDelivery::orderBy('id')->get();
    $responses = app(ParentResponseService::class);
    $responses->save($deliveries[0]->payload['token'], ['regular_needed' => false, 'waiting_needed' => false]);
    $allocation->update(['response_deadline' => now()->subMinute()]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('allocations.responses.reopen', $allocation), ['response_deadline' => '2026-11-06T12:00'])
        ->assertSessionHasNoErrors();

    expect($allocation->responses()->where('child_id', $children[0]->id)->sole()->regular_needed)->toBeFalse();
    $responses->save($deliveries[0]->payload['token'], ['regular_needed' => true, 'waiting_needed' => false]);
    $responses->save($deliveries[1]->payload['token'], ['waiting_needed' => true]);
    expect($allocation->responses()->where('child_id', $children[0]->id)->sole()->regular_needed)->toBeTrue();
    expect($allocation->responses()->where('child_id', $children[1]->id)->sole()->waiting_needed)->toBeTrue();
    $this->assertDatabaseCount('personal_access_tokens', 2);
    $this->assertDatabaseCount('email_deliveries', 2);
});

it('updates pending email deadlines without resending messages or reactivating invalid links', function () {
    $this->travelTo('2026-11-05 10:00:00');
    [$year, $children, $allocation] = careScenario(['never']);
    ParentContact::factory()->for($children[0]->family)->count(3)->create();
    app(CareCommunicationService::class)->requestResponses($allocation);
    $deliveries = EmailDelivery::orderBy('id')->get();
    $deliveries[0]->update(['sent_at' => now()]);
    $tokens = PersonalAccessToken::orderBy('id')->get();
    $tokens[0]->update(['revoked_at' => now()]);
    $tokens[1]->update(['expires_at' => now()->subMinute()]);
    app(EmergencyAllocationService::class)->close($allocation);
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('allocations.responses.reopen', $allocation), ['response_deadline' => '2026-11-06T12:00'])
        ->assertSessionHasNoErrors();

    expect($deliveries[0]->fresh()->payload['deadline'])->toBeNull();
    expect($deliveries[1]->fresh()->payload['deadline'])->toBe('06.11.2026 12:00');
    expect($deliveries[1]->fresh()->sent_at)->toBeNull();
    expect($tokens[0]->fresh()->revoked_at)->not->toBeNull();
    expect($tokens[1]->fresh()->expires_at->isPast())->toBeTrue();
    $this->getJson(route('tokens.copy', $tokens[0]))->assertUnprocessable();
    $this->getJson(route('tokens.copy', $tokens[1]))->assertUnprocessable();
    $this->getJson(route('tokens.copy', $tokens[2]))->assertOk();
    $this->assertDatabaseCount('personal_access_tokens', 3);
    $this->assertDatabaseCount('email_deliveries', 3);
    $this->assertDatabaseCount('jobs', 0);
});

it('invalidates an old proposal and requires recalculation and confirmation after reopening', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never']);
    $allocation->responses()->where('child_id', $children[0]->id)->update(['waiting_needed' => true]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $oldHash = $allocation->fresh()->proposalHash();
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('allocations.responses.reopen', $allocation), ['response_deadline' => null])->assertSessionHasNoErrors();
    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1, 'proposal_hash' => $oldHash])
        ->assertSessionHasErrors('allocation');
    $this->put(route('responses.admin.update', $allocation->responses()->where('child_id', $children[0]->id)->sole()), ['waiting_needed' => false]);
    $this->put(route('responses.admin.update', $allocation->responses()->where('child_id', $children[1]->id)->sole()), ['waiting_needed' => true]);
    $service->close($allocation);
    $newProposal = $service->propose($allocation);
    expect(collect($newProposal['rows'])->where('awarded', true)->pluck('child_id')->all())->toBe([$children[1]->id]);
    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1, 'proposal_hash' => $oldHash])
        ->assertSessionHasErrors('allocation');
    $this->assertDatabaseCount('allocation_results', 0);
});

it('rejects reopening a confirmed or already open procedure', function (bool $confirmed) {
    [$year, $children, $allocation] = careScenario(['never']);
    $admin = User::factory()->administrator()->create();
    $service = app(EmergencyAllocationService::class);
    if ($confirmed) {
        $service->close($allocation);
        $service->propose($allocation);
        $service->confirm($allocation, $admin);
    }
    $before = $allocation->fresh()->toArray();
    $this->actingAs($admin);

    $this->post(route('allocations.responses.reopen', $allocation), ['response_deadline' => null])->assertSessionHasErrors('allocation');

    expect($allocation->fresh()->toArray())->toBe($before);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'responses.reopened']);
    $this->get(route('allocations.show', $allocation))->assertOk()->assertDontSee('Rückmeldungsphase wieder eröffnen');
})->with(['confirmed' => true, 'already open' => false]);

it('rejects invalid or nonfuture deadlines when reopening', function (array $data) {
    $this->travelTo('2026-11-05 10:00:00');
    [$year, $children, $allocation] = careScenario(['never']);
    app(EmergencyAllocationService::class)->close($allocation);
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('allocations.responses.reopen', $allocation), $data)->assertSessionHasErrors('response_deadline');

    expect($allocation->fresh()->status)->toBe('closed');
    $this->assertDatabaseMissing('audit_logs', ['event' => 'responses.reopened']);
})->with([
    'missing' => [[]], 'invalid' => [['response_deadline' => 'invalid']],
    'past' => [['response_deadline' => '2026-11-05T09:00']], 'now' => [['response_deadline' => '2026-11-05T10:00']],
]);

it('authorizes reopening by administrator role and group', function (string $actor) {
    [$year, $children, $allocation] = careScenario(['never']);
    app(EmergencyAllocationService::class)->close($allocation);
    if ($actor !== 'guest') {
        $this->actingAs($actor === 'parent' ? User::factory()->create() : User::factory()->administrator()->for(CareGroup::factory(), 'group')->create());
    }

    $this->postJson(route('allocations.responses.reopen', $allocation), ['response_deadline' => null])
        ->assertStatus(match ($actor) {
            'guest' => 401, 'parent' => 403, 'other group' => 404
        });

    expect($allocation->fresh()->status)->toBe('closed');
    $this->assertDatabaseMissing('audit_logs', ['event' => 'responses.reopened']);
})->with(['guest', 'parent', 'other group']);
