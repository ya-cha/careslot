<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\EmailDelivery;
use App\Models\ParentContact;
use App\Models\User;
use App\Services\CareCommunicationService;
use App\Services\EmergencyAllocationService;

it('lets the group administrator shorten or extend an open deadline without changing answers', function (string $deadline) {
    $this->travelTo('2026-11-05 10:00:00');
    [$year, $children, $allocation] = careScenario(['always']);
    $allocation->update(['response_deadline' => '2026-11-07 12:00:00']);
    $response = $allocation->responses()->firstOrFail();
    $response->update(['regular_needed' => false, 'responded_at' => now()]);
    $this->actingAs(User::factory()->administrator()->for($allocation->emergencyDay->group, 'group')->create());

    $this->put(route('allocations.deadline.update', $allocation), ['response_deadline' => $deadline])->assertRedirect();

    expect($allocation->fresh()->response_deadline->format('Y-m-d\\TH:i'))->toBe($deadline);
    expect($response->fresh()->regular_needed)->toBeFalse();
    expect($response->fresh()->responded_at)->not->toBeNull();
    expect($allocation->fresh()->status)->toBe('responses');
    $audit = AuditLog::where('event', 'responses.deadline_updated')->sole();
    expect($audit->group_id)->toBe($allocation->emergencyDay->group_id);
    expect($audit->data['before'])->toBe('2026-11-07T12:00:00+01:00');
    $this->assertDatabaseCount('email_deliveries', 0);
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Rückmeldefrist ändern')->assertSee($deadline, false);
})->with(['shorten' => '2026-11-06T12:30', 'extend' => '2026-11-08T18:00']);

it('lets the administrator remove the deadline for manual closure', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['always']);
    $allocation->update(['response_deadline' => now()->addDay()]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('allocations.deadline.update', $allocation), ['response_deadline' => ''])->assertRedirect();
    $this->artisan('careslot:close-responses')->assertSuccessful();

    expect($allocation->fresh()->response_deadline)->toBeNull();
    expect($allocation->fresh()->status)->toBe('responses');
    expect(AuditLog::where('event', 'responses.deadline_updated')->sole()->data['after'])->toBeNull();
});

it('rejects invalid or elapsed deadlines', function (string $deadline) {
    $this->travelTo('2026-11-05 10:00:00');
    [$year, $children, $allocation] = careScenario(['always']);
    $allocation->update(['response_deadline' => '2026-11-07 12:00:00']);
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('allocations.deadline.update', $allocation), ['response_deadline' => $deadline])->assertSessionHasErrors('response_deadline');

    expect($allocation->fresh()->response_deadline->format('Y-m-d H:i:s'))->toBe('2026-11-07 12:00:00');
    $this->assertDatabaseMissing('audit_logs', ['event' => 'responses.deadline_updated']);
})->with(['invalid' => 'not-a-date', 'elapsed' => '2026-11-04T12:00', 'exactly now' => '2026-11-05T10:00']);

it('does not reopen closed proposed or confirmed procedures through a deadline change', function (string $status) {
    $this->travelTo('2026-11-05 10:00:00');
    [$year, $children, $allocation] = careScenario(['always']);
    $allocation->update(['status' => $status]);
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('allocations.deadline.update', $allocation), ['response_deadline' => '2026-11-08T18:00'])->assertSessionHasErrors(['response_deadline' => 'Die Rückmeldefrist kann nur während einer offenen Rückmeldungsphase geändert werden.']);

    expect($allocation->fresh()->status)->toBe($status);
    expect($allocation->fresh()->response_deadline)->toBeNull();
})->with(['closed', 'proposed', 'confirmed']);

it('refuses deadline edits from another group', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['always']);
    $otherGroup = CareGroup::factory()->create();
    $this->actingAs(User::factory()->administrator()->for($otherGroup, 'group')->create());

    $this->put(route('allocations.deadline.update', $allocation), ['response_deadline' => now()->addDay()->format('Y-m-d\\TH:i')])->assertNotFound();

    expect($allocation->fresh()->response_deadline)->toBeNull();
});

it('requires authentication to edit a deadline', function () {
    [$year, $children, $allocation] = careScenario(['always']);

    $this->put(route('allocations.deadline.update', $allocation), ['response_deadline' => null])->assertRedirect(route('login'));

    $this->assertDatabaseMissing('audit_logs', ['event' => 'responses.deadline_updated']);
});

it('rechecks an extended deadline after the scheduler selected an expired procedure', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['always']);
    $allocation->update(['response_deadline' => now()->subMinute()]);
    $staleAllocation = $allocation->fresh();
    $service = app(EmergencyAllocationService::class);
    $service->updateResponseDeadline($allocation, now()->addDay()->format('Y-m-d H:i:s'));

    $service->close($staleAllocation, onlyIfExpired: true);

    expect($allocation->fresh()->status)->toBe('responses');
    expect($allocation->fresh()->responses_closed_at)->toBeNull();
});

it('updates the deadline in pending request emails without sending additional messages', function () {
    $this->travelTo('2026-11-05 10:00:00');
    [$year, $children, $allocation] = careScenario(['always']);
    ParentContact::factory()->for($children[0]->family)->create();
    app(CareCommunicationService::class)->requestResponses($allocation);
    $delivery = EmailDelivery::firstOrFail();
    $token = $delivery->payload['token'];
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('allocations.deadline.update', $allocation), ['response_deadline' => '2026-11-08T18:00'])->assertRedirect();

    expect($delivery->fresh()->payload['deadline'])->toBe('08.11.2026 18:00');
    expect($delivery->fresh()->payload['token'])->toBe($token);
    expect($delivery->fresh()->sent_at)->toBeNull();
    $this->assertDatabaseCount('email_deliveries', 1);
    $this->assertDatabaseCount('jobs', 0);
});
