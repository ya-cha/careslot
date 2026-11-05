<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\EmergencyAllocationService;
use App\Services\ParentResponseService;
use Illuminate\Validation\ValidationException;

it('lets a group administrator record a missing parent response without a personal link', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['always']);
    $response = $allocation->responses()->firstOrFail();
    $admin = User::factory()->administrator()->for($allocation->emergencyDay->group, 'group')->create();
    $this->actingAs($admin);

    $this->put(route('responses.admin.update', $response), ['regular_needed' => false, 'waiting_needed' => false, 'volunteer' => true])->assertRedirect(route('allocations.show', $allocation));

    expect($response->fresh()->regular_needed)->toBeFalse();
    expect($response->fresh()->volunteer)->toBeTrue();
    expect($response->fresh()->responded_at)->not->toBeNull();
    $audit = AuditLog::where('event', 'response.admin_saved')->sole();
    expect($audit->user_id)->toBe($admin->id);
    expect($audit->group_id)->toBe($allocation->emergencyDay->group_id);
    expect($audit->data['source'])->toBe('administrator');
    expect($audit->data['administrator_id'])->toBe($admin->id);
    expect($audit->data['before']['responded_at'])->toBeNull();
    expect($audit->data['after']['regular_needed'])->toBeFalse();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('email_deliveries', 0);
    $this->assertDatabaseCount('waiting_list_movements', 0);
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Rückmeldung ändern');
});

it('lets a system administrator update an existing answer and preserves its previous values in the audit', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['never']);
    $response = $allocation->responses()->firstOrFail();
    $response->update(['waiting_needed' => true, 'volunteer' => false, 'responded_at' => now()->subHour()]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('responses.admin.update', $response), ['regular_needed' => true, 'waiting_needed' => false, 'volunteer' => true])->assertRedirect();

    expect($response->fresh()->regular_needed)->toBeNull();
    expect($response->fresh()->waiting_needed)->toBeFalse();
    expect($response->fresh()->volunteer)->toBeTrue();
    $audit = AuditLog::where('event', 'response.admin_saved')->sole();
    expect($audit->data['before']['waiting_needed'])->toBeTrue();
    expect($audit->data['after']['waiting_needed'])->toBeFalse();
});

it('uses admin-entered answers in the allocation without moving the list during entry', function () {
    [$year, $children, $allocation] = careScenario(['always', 'never']);
    $responses = $allocation->responses()->orderBy('child_id')->get();
    $this->actingAs(User::factory()->administrator()->create());
    $this->put(route('responses.admin.update', $responses[0]), ['regular_needed' => false, 'waiting_needed' => true, 'volunteer' => false])->assertRedirect();
    $this->put(route('responses.admin.update', $responses[1]), ['waiting_needed' => true, 'volunteer' => false])->assertRedirect();
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);

    $proposal = $service->propose($allocation);

    expect(array_column($proposal['rows'], 'phase'))->toBe([null, 2]);
    expect($responses[0]->fresh()->waiting_needed)->toBeFalse();
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseCount('allocation_results', 0);
});

it('lets parents revise an admin-entered response while the phase is still open', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $response = $allocation->responses()->firstOrFail();
    $contact = ParentContact::factory()->for($children[0]->family)->create();
    $plainToken = bin2hex(random_bytes(32));
    PersonalAccessToken::factory()->for($response, 'response')->for($contact)->create(['token_hash' => hash('sha256', $plainToken)]);
    $this->actingAs(User::factory()->administrator()->create());
    $this->put(route('responses.admin.update', $response), ['regular_needed' => false, 'waiting_needed' => false, 'volunteer' => false])->assertRedirect();
    $this->post(route('logout'))->assertRedirect();

    $this->post(route('parent.update', $plainToken), ['regular_needed' => true, 'waiting_needed' => false, 'volunteer' => false])->assertRedirect();

    expect($response->fresh()->regular_needed)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['event' => 'response.admin_saved']);
    $this->assertDatabaseHas('audit_logs', ['event' => 'response.saved']);
});

it('does not let admins change confirmed responses or final allocation results', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $admin = User::factory()->administrator()->create();
    $service->close($allocation);
    $service->propose($allocation);
    $service->confirm($allocation, $admin);
    $response = $allocation->responses()->firstOrFail();
    $result = $allocation->results()->firstOrFail();
    $snapshot = $result->snapshot;
    $this->actingAs($admin);

    $this->put(route('responses.admin.update', $response), ['regular_needed' => false, 'waiting_needed' => false, 'volunteer' => true])->assertSessionHasErrors(['response' => 'Die Vergabe ist bereits bestätigt. Änderungen erfordern ein neues Verfahren.']);

    expect($response->fresh()->regular_needed)->toBeNull();
    expect($result->fresh()->awarded)->toBeTrue();
    expect($result->fresh()->snapshot)->toBe($snapshot);
    expect($allocation->fresh()->status)->toBe('confirmed');
    $this->assertDatabaseMissing('audit_logs', ['event' => 'response.admin_saved']);
});

it('denies a group administrator access to another groups responses before validation', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $response = $allocation->responses()->firstOrFail();
    $otherGroup = CareGroup::factory()->create();
    $this->actingAs(User::factory()->administrator()->for($otherGroup, 'group')->create());

    $this->put(route('responses.admin.update', $response), [])->assertNotFound();

    expect($response->fresh()->responded_at)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['event' => 'response.admin_saved']);
});

it('requires an authenticated administrator to enter parent responses', function (bool $authenticated) {
    [$year, $children, $allocation] = careScenario(['never']);
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    $response = $allocation->responses()->firstOrFail();

    $result = $this->put(route('responses.admin.update', $response), ['waiting_needed' => true, 'volunteer' => true]);

    if ($authenticated) {
        $result->assertForbidden();
    } else {
        $result->assertRedirect(route('login'));
    }
    expect($response->fresh()->responded_at)->toBeNull();
})->with(['guest' => false, 'non-admin' => true]);

it('requires an explicit regular care decision for entitled children', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $response = $allocation->responses()->firstOrFail();
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('responses.admin.update', $response), ['waiting_needed' => false, 'volunteer' => false])->assertSessionHasErrors(['regular_needed' => 'Bitte den Bedarf am regulären Platz angeben.']);

    expect($response->fresh()->responded_at)->toBeNull();
});

it('validates admin-entered answers with the same boolean rules as parent answers', function (string $field) {
    [$year, $children, $allocation] = careScenario(['always']);
    $response = $allocation->responses()->firstOrFail();
    $this->actingAs(User::factory()->administrator()->create());
    $payload = ['regular_needed' => true, 'waiting_needed' => false, 'volunteer' => false];
    $payload[$field] = 'invalid';

    $this->put(route('responses.admin.update', $response), $payload)->assertSessionHasErrors($field);

    expect($response->fresh()->responded_at)->toBeNull();
})->with(['regular_needed', 'waiting_needed', 'volunteer']);

it('does not permit response entry for inactive or transferred children', function (bool $transferred) {
    [$year, $children, $allocation] = careScenario(['never']);
    $response = $allocation->responses()->firstOrFail();
    $children[0]->update($transferred ? ['group_id' => CareGroup::factory()->create()->id] : ['active' => false]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('responses.admin.update', $response), ['waiting_needed' => true, 'volunteer' => false])->assertNotFound();

    expect($response->fresh()->responded_at)->toBeNull();
})->with(['inactive' => false, 'transferred' => true]);

it('ignores spoofed child and procedure ids when recording a response', function () {
    [$year, $children, $allocation] = careScenario(['never', 'never']);
    $responses = $allocation->responses()->orderBy('child_id')->get();
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('responses.admin.update', $responses[0]), ['child_id' => $children[1]->id, 'emergency_allocation_id' => 99999, 'waiting_needed' => true, 'volunteer' => false])->assertRedirect();

    expect($responses[0]->fresh()->child_id)->toBe($children[0]->id);
    expect($responses[0]->fresh()->emergency_allocation_id)->toBe($allocation->id);
    expect($responses[0]->fresh()->waiting_needed)->toBeTrue();
    expect($responses[1]->fresh()->responded_at)->toBeNull();
});

it('allows admin corrections after the deadline or manual closure while keeping parent access closed', function (string $state) {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['always']);
    $allocation->update(['response_deadline' => now()->subMinute()]);
    if ($state === 'closed') {
        app(EmergencyAllocationService::class)->close($allocation);
    }
    $closedAt = $allocation->fresh()->responses_closed_at;
    $response = $allocation->responses()->firstOrFail();
    $contact = ParentContact::factory()->for($children[0]->family)->create();
    $plain = bin2hex(random_bytes(32));
    PersonalAccessToken::factory()->for($response, 'response')->for($contact)->create(['token_hash' => hash('sha256', $plain)]);
    $this->actingAs(User::factory()->administrator()->for($allocation->emergencyDay->group, 'group')->create());

    $this->put(route('responses.admin.update', $response), ['regular_needed' => false, 'waiting_needed' => false, 'volunteer' => true])->assertRedirect();

    expect($response->fresh()->regular_needed)->toBeFalse();
    expect($response->fresh()->volunteer)->toBeTrue();
    expect($allocation->fresh()->status)->toBe($state);
    expect($allocation->fresh()->responses_closed_at)->toEqual($closedAt);
    $audit = AuditLog::where('event', 'response.admin_saved')->sole();
    expect($audit->data['after_response_deadline'])->toBeTrue();
    expect($audit->data['allocation_status_before'])->toBe($state);
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Rückmeldung ändern');
    $this->post(route('parent.update', $plain), ['regular_needed' => true, 'waiting_needed' => false, 'volunteer' => false])->assertSessionHasErrors(['response' => 'Die Rückmeldungsphase ist geschlossen.']);
    expect($response->fresh()->regular_needed)->toBeFalse();
    $this->assertDatabaseCount('allocation_results', 0);
    $this->assertDatabaseCount('waiting_list_movements', 0);
    $this->assertDatabaseCount('email_deliveries', 0);
})->with(['expired deadline' => 'responses', 'manually closed' => 'closed']);

it('archives and invalidates the proposal after an admin correction and refuses stale confirmations', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['never', 'never']);
    $allocation->responses()->update(['volunteer' => true, 'responded_at' => now()]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $oldProposal = $service->propose($allocation);
    $oldHash = $allocation->fresh()->proposalHash();
    $response = $allocation->responses()->where('child_id', $children[0]->id)->firstOrFail();
    $this->actingAs(User::factory()->administrator()->create());
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Geänderte Angaben verwerfen den aktuellen Vorschlag.');

    $this->put(route('responses.admin.update', $response), ['waiting_needed' => false, 'volunteer' => false])->assertRedirect();

    expect($allocation->fresh()->status)->toBe('closed');
    expect($allocation->fresh()->proposal)->toBeNull();
    expect($allocation->fresh()->source_revision)->toBeNull();
    expect($allocation->fresh()->calculated_at)->toBeNull();
    $this->assertDatabaseCount('lottery_draws', 0);
    $this->assertDatabaseCount('allocation_results', 0);
    $this->assertDatabaseCount('waiting_list_movements', 0);
    $this->assertDatabaseCount('email_deliveries', 0);
    $discard = AuditLog::where('event', 'allocation.proposal_discarded')->sole();
    expect($discard->data['proposal'])->toBe($oldProposal);
    expect($discard->data['reason'])->toBe('Rückmeldung durch Admin korrigiert.');
    expect(AuditLog::where('event', 'response.admin_saved')->sole()->data['proposal_invalidated'])->toBeTrue();
    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1, 'proposal_hash' => $oldHash])->assertSessionHasErrors('allocation');

    $service->propose($allocation);
    $newHash = $allocation->fresh()->proposalHash();
    expect($newHash)->not->toBe($oldHash);
    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1, 'proposal_hash' => $oldHash])->assertSessionHasErrors(['allocation' => 'Der Vorschlag hat sich geändert. Bitte den aktuellen Vorschlag erneut prüfen und bestätigen.']);
    $this->assertDatabaseCount('allocation_results', 0);
    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1, 'proposal_hash' => $newHash])->assertRedirect();
    expect($allocation->fresh()->status)->toBe('confirmed');
    $this->assertDatabaseHas('allocation_results', ['child_id' => $children[1]->id, 'awarded' => true, 'phase' => 3]);
    $this->assertDatabaseHas('allocation_results', ['child_id' => $children[0]->id, 'awarded' => false]);
});

it('preserves an existing draw when an admin saves unchanged answers', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['never', 'never']);
    $allocation->responses()->update(['volunteer' => true, 'responded_at' => now()]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $proposal = $service->propose($allocation);
    $response = $allocation->responses()->firstOrFail();
    $this->travel(1)->minute();
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('responses.admin.update', $response), ['waiting_needed' => false, 'volunteer' => true])->assertRedirect();

    expect($allocation->fresh()->status)->toBe('proposed');
    expect($allocation->fresh()->proposal)->toBe($proposal);
    $this->assertDatabaseCount('lottery_draws', 1);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'allocation.proposal_discarded']);
    expect(AuditLog::where('event', 'response.admin_saved')->sole()->data['proposal_invalidated'])->toBeFalse();
});

it('does not discard a proposal when a correction fails validation', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $proposal = $service->propose($allocation);
    $response = $allocation->responses()->firstOrFail();
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('responses.admin.update', $response), ['waiting_needed' => false, 'volunteer' => false])->assertSessionHasErrors('regular_needed');

    expect($allocation->fresh()->status)->toBe('proposed');
    expect($allocation->fresh()->proposal)->toBe($proposal);
    expect($response->fresh()->responded_at)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['event' => 'allocation.proposal_discarded']);
});

it('requires the reviewed proposal version when confirming through the browser', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1])->assertSessionHasErrors('proposal_hash');

    expect($allocation->fresh()->status)->toBe('proposed');
    $this->assertDatabaseCount('allocation_results', 0);
});

it('rejects a stale admin correction if confirmation has already completed', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $staleResponse = $allocation->responses()->with('allocation.emergencyDay')->firstOrFail();
    $admin = User::factory()->administrator()->create();
    $service->confirm($allocation, $admin);

    expect(fn () => app(ParentResponseService::class)->saveAsAdministrator($staleResponse, ['regular_needed' => false, 'waiting_needed' => false, 'volunteer' => false], $admin))->toThrow(ValidationException::class, 'bereits bestätigt');

    expect($staleResponse->fresh()->regular_needed)->toBeNull();
    $this->assertDatabaseHas('allocation_results', ['child_id' => $children[0]->id, 'awarded' => true]);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'response.admin_saved']);
});
