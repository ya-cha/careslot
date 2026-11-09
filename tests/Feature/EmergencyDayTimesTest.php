<?php

use App\Models\EmailDelivery;
use App\Models\EmergencyDay;
use App\Models\ParentContact;
use App\Models\RegularCareAssignment;
use App\Models\User;
use App\Notifications\CareMessage;
use App\Services\CareCommunicationService;
use App\Services\EmergencyAllocationService;

it('stores independent optional time boundaries and displays them for admins parents and emails', function (?string $start, ?string $end, string $label) {
    [$year, $children] = careScenario(['always']);
    $child = $children[0];
    RegularCareAssignment::factory()->for($child)->create(['weekday' => 2, 'pattern' => 'always']);
    $contact = ParentContact::factory()->for($child->family)->create();
    $admin = User::factory()->administrator()->for($child->group, 'group')->create();
    $this->actingAs($admin);

    $this->post(route('days.store'), [
        'group_id' => $child->group_id, 'kita_year_id' => $year->id, 'date' => '2026-10-27',
        'capacity' => 1, 'starts_at' => $start, 'ends_at' => $end,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $day = EmergencyDay::whereDate('date', '2026-10-27')->sole();
    expect($day->starts_at)->toBe($start);
    expect($day->ends_at)->toBe($end);
    $allocation = $day->allocations()->sole();
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee($label);
    app(CareCommunicationService::class)->requestResponses($allocation);
    $delivery = EmailDelivery::where('emergency_allocation_id', $allocation->id)->sole();
    $this->get(route('parent.show', $delivery->payload['token']))->assertOk()->assertSee($label);
    $mail = new CareMessage('request', $delivery->payload);
    expect($mail->toMail(new stdClass)->introLines)->toContain($label.'.');

    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $service->confirm($allocation, $admin);
    $resultDelivery = EmailDelivery::where('emergency_allocation_id', $allocation->id)->where('kind', 'result')->sole();
    $this->get(route('parent.show', $resultDelivery->payload['token']))->assertOk()->assertSee($label);
    expect((new CareMessage('result', $resultDelivery->payload))->toMail(new stdClass)->introLines)->toContain($label.'.');
})->with([
    'start only' => ['12:00', null, 'Notbetreuung ab 12:00 Uhr'],
    'end only' => [null, '14:00', 'Notbetreuung bis 14:00 Uhr'],
    'both' => ['08:00', '16:00', 'Notbetreuung von 08:00 bis 16:00 Uhr'],
    'neither' => [null, null, 'Ganztägige Notbetreuung'],
]);

it('rejects invalid times and requires an end after the start only when both are provided', function (?string $start, ?string $end, string $field) {
    [$year, $children] = careScenario(['never']);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    $this->post(route('days.store'), [
        'group_id' => $children[0]->group_id, 'kita_year_id' => $year->id, 'date' => '2026-10-27',
        'capacity' => 1, 'starts_at' => $start, 'ends_at' => $end,
    ])->assertSessionHasErrors($field);

    expect(EmergencyDay::whereDate('date', '2026-10-27')->exists())->toBeFalse();
})->with([
    ['25:00', null, 'starts_at'], [null, 'invalid', 'ends_at'],
    ['12:00', '11:00', 'ends_at'], ['12:00', '12:00', 'ends_at'],
]);
