<?php

use App\Jobs\SendCareEmail;
use App\Models\CareGroup;
use App\Models\Child;
use App\Models\EmailDelivery;
use App\Models\EmergencyAllocation;
use App\Models\EmergencyResponse;
use App\Models\Family;
use App\Models\KitaYear;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\RegularCareAssignment;
use App\Models\User;
use App\Notifications\CareMessage;
use App\Services\CareCommunicationService;
use App\Services\EmergencyAllocationService;
use App\Services\WaitingListService;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/** @return array{0: string, 1: EmergencyResponse} */
function parentAccess(bool $regular = false): array
{
    $response = EmergencyResponse::factory()->create(['claim_snapshot' => ['status' => $regular ? 'full' : 'none', 'starts_at' => null, 'ends_at' => null, 'rules' => [], 'explanation' => 'Gespeicherter Anspruch.']]);
    $contact = ParentContact::factory()->for($response->child->family)->create();
    $plain = bin2hex(random_bytes(32));
    PersonalAccessToken::factory()->for($response, 'response')->for($contact)->create(['token_hash' => hash('sha256', $plain)]);

    return [$plain, $response];
}

it('requires administrator privileges for all management pages', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('children.index'))->assertForbidden();
    $this->post(route('years.store'), ['name' => 'Forbidden'])->assertForbidden();
    $this->assertDatabaseCount('kita_years', 0);
});

it('authenticates administrators and logs them out', function () {
    $admin = User::factory()->administrator()->create();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($admin);
    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('rejects incorrect passwords and non administrator accounts', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors(['email' => 'Die Zugangsdaten sind ungültig.']);
    $this->assertGuest();
});

it('renders management pages with persisted data', function () {
    $admin = User::factory()->administrator()->create();
    [$year, $children, $allocation] = careScenario(['always']);
    $this->actingAs($admin);

    foreach (['dashboard', 'years.index', 'children.index', 'children.create', 'waiting-list', 'days.create', 'history'] as $route) {
        $this->get(route($route))->assertOk()->assertSee('careslot');
    }
    $this->get(route('children.edit', $children[0]))->assertOk()->assertSee('Montag')->assertSee('Freitag');
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Rückmeldungen offen');
});

it('filters the live waiting list and rechecks authorization', function () {
    [$year, $children] = careScenario(['never', 'never']);
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->set('search', 'Anna')->assertSee('Anna')->assertDontSee('Ben Beispiel');
});

it('renders only the child authorized by the token and prevents choosing another child', function () {
    [$plain, $response] = parentAccess();
    $other = Child::factory()->create(['first_name' => 'FremdesKindGeheim']);

    $this->get(route('parent.show', $plain))->assertOk()->assertSee($response->child->first_name)->assertDontSee('FremdesKindGeheim')->assertHeader('Referrer-Policy', 'no-referrer');
    $this->post(route('parent.update', $plain), ['child_id' => $other->id, 'waiting_needed' => true, 'volunteer' => true])->assertRedirect(route('parent.show', $plain));

    expect($response->fresh()->waiting_needed)->toBeTrue();
    $this->assertDatabaseMissing('emergency_responses', ['child_id' => $other->id]);
});

it('allows changing responses while open and validates regular demand', function () {
    [$plain, $response] = parentAccess(true);
    $url = route('parent.update', $plain);
    $this->post($url, ['waiting_needed' => false, 'volunteer' => false])->assertSessionHasErrors(['regular_needed' => 'Bitte den Bedarf am regulären Platz angeben.']);
    $this->post($url, ['regular_needed' => '0', 'waiting_needed' => true, 'volunteer' => false])->assertRedirect();
    expect($response->fresh()->regular_needed)->toBeFalse();
    expect($response->fresh()->waiting_needed)->toBeFalse();

    $this->post($url, ['regular_needed' => '1', 'waiting_needed' => false, 'volunteer' => true])->assertRedirect();

    expect($response->fresh()->regular_needed)->toBeTrue();
    expect($response->fresh()->volunteer)->toBeTrue();
});

it('refuses unknown revoked expired and malformed tokens', function (string $state) {
    $this->freezeTime();
    [$plain, $response] = parentAccess();
    $token = $response->tokens()->first();
    if ($state === 'revoked') {
        $token->update(['revoked_at' => now()]);
    }
    if ($state === 'expired') {
        $token->update(['expires_at' => now()->subSecond()]);
    }
    if ($state === 'unknown') {
        $plain = str_repeat('a', 64);
    }
    if ($state === 'malformed') {
        $plain = 'invalid';
    }

    $this->get(route('parent.show', $plain))->assertNotFound();
    $this->post(route('parent.update', $plain), ['waiting_needed' => true, 'volunteer' => true])->assertNotFound();
    expect($response->fresh()->responded_at)->toBeNull();
})->with(['revoked', 'expired', 'unknown', 'malformed']);

it('blocks parent edits at the deadline and after manual closure', function (string $state) {
    $this->freezeTime();
    [$plain, $response] = parentAccess();
    $response->allocation->update($state === 'deadline' ? ['response_deadline' => now()] : ['status' => 'closed']);

    $this->post(route('parent.update', $plain), ['waiting_needed' => true, 'volunteer' => true])->assertSessionHasErrors(['response' => 'Die Rückmeldungsphase ist geschlossen.']);

    expect($response->fresh()->responded_at)->toBeNull();
})->with(['deadline', 'closed']);

it('queues hashed parent links for every contact and creates final mail only once', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    ParentContact::factory()->count(2)->for($children[0]->family)->create();
    $communication = app(CareCommunicationService::class);
    $communication->requestResponses($allocation);
    $request = EmailDelivery::first();
    $plain = $request->payload['token'];
    expect(PersonalAccessToken::first()->token_hash)->toBe(hash('sha256', $plain));
    expect($request->getRawOriginal('payload'))->not->toContain($plain);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $admin = User::factory()->administrator()->create();

    $service->confirm($allocation, $admin);
    $service->confirm($allocation, $admin);

    expect(EmailDelivery::where('kind', 'result')->count())->toBe(2);
    $this->assertDatabaseCount('email_deliveries', 4);
    $this->assertDatabaseCount('allocation_results', 1);
});

it('sends queued notifications idempotently', function () {
    Notification::fake();
    [$year, $children, $allocation] = careScenario(['never']);
    ParentContact::factory()->for($children[0]->family)->create();
    app(CareCommunicationService::class)->requestResponses($allocation);
    $delivery = EmailDelivery::first();
    $job = new SendCareEmail($delivery->id);

    $job->handle();
    $job->handle();

    Notification::assertSentOnDemandTimes(CareMessage::class, 1);
    expect($delivery->fresh()->attempts)->toBe(1);
    expect($delivery->fresh()->sent_at)->not->toBeNull();
});

it('records failed email attempts without rolling back confirmed allocations', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    ParentContact::factory()->for($children[0]->family)->create();
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $service->confirm($allocation, User::factory()->administrator()->create());
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP unreachable'));
    $delivery = EmailDelivery::first();

    expect(fn () => (new SendCareEmail($delivery->id))->handle())->toThrow(RuntimeException::class, 'SMTP unreachable');

    expect($allocation->fresh()->status)->toBe('confirmed');
    expect($delivery->fresh()->last_error)->toContain('Versand fehlgeschlagen');
    expect($delivery->fresh()->attempts)->toBe(1);
    $this->assertDatabaseCount('allocation_results', 1);
});

it('creates children with five validated rules and appends them without resorting', function () {
    $year = KitaYear::factory()->create();
    $group = CareGroup::factory()->create();
    app(WaitingListService::class)->initialize($year, $group);
    $this->actingAs(User::factory()->administrator()->create());
    $rules = collect(range(1, 5))->map(fn ($day) => ['weekday' => $day, 'pattern' => 'odd', 'time_mode' => 'from', 'starts_at' => '12:30', 'ends_at' => null, 'valid_from' => '2026-08-01', 'valid_until' => null])->all();

    $this->post(route('children.store'), ['group_id' => $group->id, 'kita_year_id' => $year->id, 'first_name' => 'Anna', 'last_name' => 'Müller', 'active' => 1, 'family_name' => 'Müller', 'contacts' => [['name' => 'Eltern', 'email' => 'eltern@example.com'], ['name' => '', 'email' => '']], 'rules' => $rules])->assertRedirect();

    $this->assertDatabaseHas('children', ['first_name' => 'Anna']);
    $this->assertDatabaseHas('waiting_list_entries', ['position' => 1]);
    $this->assertDatabaseCount('regular_care_assignments', 5);
    $this->assertDatabaseCount('parent_contacts', 1);
});

it('ignores removed individual care times when saving child rules', function () {
    $year = KitaYear::factory()->create();
    $group = CareGroup::factory()->create();
    $family = Family::factory()->for($group, 'group')->create();
    $this->actingAs(User::factory()->administrator()->create());
    $rules = collect(range(1, 5))->map(fn ($day) => ['weekday' => $day, 'pattern' => 'always', 'time_mode' => 'window', 'starts_at' => '16:00', 'ends_at' => '12:00', 'valid_from' => '2026-08-01'])->all();

    $this->post(route('children.store'), ['group_id' => $group->id, 'kita_year_id' => $year->id, 'family_id' => $family->id, 'first_name' => 'Anna', 'last_name' => 'Müller', 'active' => 1, 'rules' => $rules])->assertRedirect();

    $this->assertDatabaseCount('children', 1);
    expect(RegularCareAssignment::first()->getAttributes())->not->toHaveKeys(['time_mode', 'starts_at', 'ends_at']);
});

it('requires explicit confirmation through the admin endpoint', function () {
    [$year, $children, $allocation] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('allocations.action', $allocation), ['action' => 'confirm'])->assertSessionHasErrors('confirmation');
    expect($allocation->fresh()->status)->toBe('proposed');
    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1, 'proposal_hash' => $allocation->fresh()->proposalHash()])->assertRedirect();
    $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Verbindliches Ergebnis');
    expect($allocation->fresh()->status)->toBe('confirmed');
});

it('creates optional fictional demo data without mail or administrator accounts', function () {
    $this->freezeTime();

    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $this->assertDatabaseCount('children', 6);
    $this->assertDatabaseCount('regular_care_assignments', 30);
    $this->assertDatabaseCount('emergency_days', 2);
    $this->assertDatabaseHas('emergency_allocations', ['status' => 'responses']);
    $this->assertDatabaseHas('emergency_allocations', ['status' => 'proposed']);
    $this->assertDatabaseCount('lottery_draws', 1);
    $proposal = EmergencyAllocation::where('status', 'proposed')->firstOrFail()->proposal;
    expect(collect($proposal['rows'])->pluck('phase')->filter()->countBy()->sortKeys()->all())->toBe([1 => 1, 2 => 1, 3 => 2]);
    $this->assertDatabaseCount('allocation_results', 0);
    $this->assertDatabaseCount('waiting_list_movements', 0);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('email_deliveries', 0);
});

it('closes expired response phases through the scheduled command', function () {
    $this->freezeTime();
    [$year, $children, $allocation] = careScenario(['always']);
    $allocation->update(['response_deadline' => now()->subMinute()]);

    $this->artisan('careslot:close-responses')->assertSuccessful();

    expect($allocation->fresh()->status)->toBe('closed');
    expect($allocation->responses()->first()->regular_needed)->toBeNull();
});

it('queues reminders only for children without a response', function () {
    [$year, $children, $allocation] = careScenario(['always', 'never']);
    foreach ($children as $child) {
        ParentContact::factory()->for($child->family)->create();
    }
    $allocation->responses()->where('child_id', $children[0]->id)->update(['responded_at' => now(), 'regular_needed' => true]);

    app(CareCommunicationService::class)->requestResponses($allocation, true);

    $this->assertDatabaseCount('email_deliveries', 1);
    expect(EmailDelivery::first()->payload['child_name'])->toBe('Ben Beispiel');
});

it('does not send pending messages whose personal link has been revoked', function () {
    Notification::fake();
    [$year, $children, $allocation] = careScenario(['always']);
    ParentContact::factory()->for($children[0]->family)->create();
    app(CareCommunicationService::class)->requestResponses($allocation);
    PersonalAccessToken::query()->update(['revoked_at' => now()]);
    $delivery = EmailDelivery::first();

    (new SendCareEmail($delivery->id))->handle();

    Notification::assertNothingSent();
    expect($delivery->fresh()->sent_at)->toBeNull();
    expect($delivery->fresh()->last_error)->toContain('Versand abgebrochen');
});

it('revokes previous links when a contact email is corrected', function () {
    [$plain, $response] = parentAccess();
    $contact = $response->tokens()->first()->parentContact;
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('contacts.update', $contact), ['name' => $contact->name, 'email' => 'corrected@example.com'])->assertRedirect();

    $this->get(route('parent.show', $plain))->assertNotFound();
    expect($contact->fresh()->email)->toBe('corrected@example.com');
});
