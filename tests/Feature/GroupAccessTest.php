<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\Child;
use App\Models\EmailDelivery;
use App\Models\EmergencyDay;
use App\Models\Family;
use App\Models\KitaYear;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\RegularCareAssignment;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CareCommunicationService;
use App\Services\EmergencyAllocationService;
use App\Services\WaitingListService;
use Database\Seeders\CareGroupSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** @return array{year: KitaYear, own: array, foreign: array} */
function groupedCareScenario(): array
{
    $year = KitaYear::factory()->create();
    $scenario = ['year' => $year];
    foreach (['own' => 'Bären', 'foreign' => 'PrivateFuchsgruppe'] as $key => $name) {
        $group = CareGroup::factory()->create(['name' => $name]);
        $family = Family::factory()->for($group, 'group')->create(['name' => $name.' Familie']);
        $child = Child::factory()->for($year)->for($group, 'group')->for($family)->create(['first_name' => $key === 'own' ? 'Anna' : 'PrivateFuchskind']);
        $contact = ParentContact::factory()->for($family)->create(['email' => $key.'@example.com']);
        foreach (range(1, 5) as $weekday) {
            RegularCareAssignment::factory()->for($child)->create(['weekday' => $weekday, 'pattern' => 'never']);
        }
        app(WaitingListService::class)->initialize($year, $group);
        $day = EmergencyDay::factory()->for($year)->for($group, 'group')->create(['capacity' => 1]);
        $allocation = app(EmergencyAllocationService::class)->start($day, 1);
        $scenario[$key] = compact('group', 'family', 'child', 'contact', 'day', 'allocation');
    }

    return $scenario;
}

/** @return array<string, mixed> */
function groupChildPayload(KitaYear $year, CareGroup $group, ?Family $family = null): array
{
    return ['group_id' => $group->id, 'kita_year_id' => $year->id, 'family_id' => $family?->id, 'family_name' => $family ? null : 'Neue Familie', 'first_name' => 'NeuesKind', 'last_name' => 'Beispiel', 'active' => true, 'contacts' => $family ? [] : [['name' => 'Eltern', 'email' => 'new@example.com']], 'rules' => collect(range(1, 5))->map(fn ($weekday) => ['weekday' => $weekday, 'pattern' => 'always', 'valid_from' => '2026-08-01', 'valid_until' => null])->all()];
}

it('limits group administrators to their children contacts and procedures across all lists', function () {
    $data = groupedCareScenario();
    $admin = User::factory()->administrator()->for($data['own']['group'], 'group')->create();
    app(AuditService::class)->record('own.action', $data['own']['child'], ['text' => 'EigenerEintrag']);
    app(AuditService::class)->record('foreign.action', $data['foreign']['child'], ['text' => 'FremderEintrag']);
    $this->actingAs($admin);

    $this->get(route('dashboard'))->assertOk()->assertViewHas('childrenCount', 1)->assertDontSee('PrivateFuchsgruppe');
    $this->get(route('children.index'))->assertOk()->assertSee('Anna')->assertDontSee('PrivateFuchskind')->assertDontSee('foreign@example.com');
    $this->get(route('children.create'))->assertOk()->assertDontSee('PrivateFuchsgruppe')->assertDontSee('foreign@example.com');
    $this->get(route('years.index'))->assertOk()->assertSee('Bären')->assertDontSee('PrivateFuchsgruppe');
    $this->get(route('days.create'))->assertOk()->assertDontSee('PrivateFuchsgruppe');
    $this->get(route('history'))->assertOk()->assertSee('EigenerEintrag')->assertDontSee('FremderEintrag');
});

it('refuses direct reads and writes to records of another group', function (string $target) {
    $data = groupedCareScenario();
    $foreign = $data['foreign'];
    $this->actingAs(User::factory()->administrator()->for($data['own']['group'], 'group')->create());

    match ($target) {
        'child' => $this->get(route('children.edit', $foreign['child']))->assertNotFound(),
        'child-write' => $this->put(route('children.update', $foreign['child']), groupChildPayload($data['year'], $data['own']['group']))->assertNotFound(),
        'allocation' => $this->get(route('allocations.show', $foreign['allocation']))->assertNotFound(),
        'close' => $this->post(route('allocations.action', $foreign['allocation']), ['action' => 'close'])->assertNotFound(),
        'contact' => $this->put(route('contacts.update', $foreign['contact']), ['name' => 'Changed', 'email' => 'changed@example.com'])->assertNotFound(),
        'initialize' => $this->post(route('years.initialize', $data['year']), ['group_id' => $foreign['group']->id])->assertNotFound(),
        'restart' => $this->post(route('allocations.restart', $foreign['allocation']), ['capacity' => 1, 'reason' => 'Changed'])->assertNotFound(),
    };

    expect($foreign['allocation']->fresh()->status)->toBe('responses');
    expect($foreign['contact']->fresh()->email)->toBe('foreign@example.com');
})->with(['child', 'child-write', 'allocation', 'close', 'contact', 'initialize', 'restart']);

it('refuses foreign groups and families supplied in creation forms', function (string $target) {
    $data = groupedCareScenario();
    $this->actingAs(User::factory()->administrator()->for($data['own']['group'], 'group')->create());

    if ($target === 'day') {
        $this->post(route('days.store'), ['group_id' => $data['foreign']['group']->id, 'kita_year_id' => $data['year']->id, 'date' => '2026-11-02', 'capacity' => 1])->assertSessionHasErrors('group_id');
    } else {
        $payload = groupChildPayload($data['year'], $target === 'group' ? $data['foreign']['group'] : $data['own']['group'], $data['foreign']['family']);
        $this->post(route('children.store'), $payload)->assertSessionHasErrors($target === 'group' ? 'group_id' : 'family_id');
    }

    $this->assertDatabaseCount('children', 2);
    $this->assertDatabaseCount('emergency_days', 2);
})->with(['group', 'family', 'day']);

it('allows a group administrator to create children and initialize their group in another year', function () {
    $data = groupedCareScenario();
    $year = KitaYear::factory()->create();
    $group = $data['own']['group'];
    $this->actingAs(User::factory()->administrator()->for($group, 'group')->create());

    $this->post(route('children.store'), groupChildPayload($year, $group))->assertRedirect();
    $this->post(route('years.initialize', $year), ['group_id' => $group->id])->assertRedirect();

    $this->assertDatabaseHas('children', ['group_id' => $group->id, 'first_name' => 'NeuesKind']);
    $this->assertDatabaseHas('waiting_list_entries', ['group_id' => $group->id, 'kita_year_id' => $year->id, 'position' => 1]);
});

it('does not allow group administrators to change groups accounts or shared years', function () {
    $data = groupedCareScenario();
    $admin = User::factory()->administrator()->for($data['own']['group'], 'group')->create();
    $this->actingAs($admin);

    $this->get(route('groups.index'))->assertForbidden();
    $this->post(route('groups.store'), ['name' => 'Unauthorized'])->assertForbidden();
    $this->post(route('groups.admins'), ['user_id' => $admin->id, 'group_id' => $data['foreign']['group']->id])->assertForbidden();
    $this->post(route('years.store'), ['name' => 'Unauthorized'])->assertForbidden();
    $this->post(route('years.archive', $data['year']))->assertForbidden();

    expect($admin->fresh()->group_id)->toBe($data['own']['group']->id);
});

it('rechecks the selected waiting list group on every Livewire update', function () {
    $data = groupedCareScenario();
    $this->actingAs(User::factory()->administrator()->for($data['own']['group'], 'group')->create());

    Livewire::test('waiting-list')->assertSee('Anna')->assertDontSee('PrivateFuchskind')->set('groupId', (string) $data['foreign']['group']->id)->assertStatus(404);
});

it('protects email resending and token revocation across groups', function (string $action) {
    $data = groupedCareScenario();
    app(CareCommunicationService::class)->requestResponses($data['foreign']['allocation']);
    $token = PersonalAccessToken::firstOrFail();
    $delivery = EmailDelivery::firstOrFail();
    $this->actingAs(User::factory()->administrator()->for($data['own']['group'], 'group')->create());

    $url = $action === 'revoke' ? route('tokens.revoke', $token) : route('mail.resend', $delivery);
    $this->post($url)->assertNotFound();

    expect($token->fresh()->revoked_at)->toBeNull();
    $this->assertDatabaseCount('email_deliveries', 1);
})->with(['revoke', 'resend']);

it('allocates and rotates each group independently within the same year', function () {
    $data = groupedCareScenario();
    $service = app(EmergencyAllocationService::class);
    foreach (['own', 'foreign'] as $key) {
        $allocation = $data[$key]['allocation'];
        $allocation->responses()->update(['waiting_needed' => true]);
        $service->close($allocation);
        $proposal = $service->propose($allocation);
        expect(array_column($proposal['rows'], 'child_id'))->toBe([$data[$key]['child']->id]);
    }
    $ownAdmin = User::factory()->administrator()->for($data['own']['group'], 'group')->create();

    $service->confirm($data['own']['allocation'], $ownAdmin);
    $service->confirm($data['foreign']['allocation'], User::factory()->administrator()->for($data['foreign']['group'], 'group')->create());

    $this->assertDatabaseHas('waiting_list_entries', ['group_id' => $data['own']['group']->id, 'position' => 1]);
    $this->assertDatabaseHas('waiting_list_entries', ['group_id' => $data['foreign']['group']->id, 'position' => 1]);
    expect($data['foreign']['allocation']->fresh()->status)->toBe('confirmed');
});

it('allows the system administrator to manage groups and assign group administrators', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $user = User::factory()->create();

    $this->post(route('groups.store'), ['name' => 'Sonnengruppe'])->assertRedirect();
    $group = CareGroup::where('name', 'Sonnengruppe')->sole();
    $this->post(route('groups.admins'), ['user_id' => $user->id, 'group_id' => $group->id])->assertRedirect();
    $this->get(route('groups.index'))->assertOk()->assertSee('Sonnengruppe');

    expect($user->fresh()->is_admin)->toBeTrue();
    expect($user->fresh()->group_id)->toBe($group->id);
});

it('renders child forms without individual hours or promotional copy', function () {
    $data = groupedCareScenario();
    $this->actingAs(User::factory()->administrator()->for($data['own']['group'], 'group')->create());

    $this->get(route('children.edit', $data['own']['child']))->assertOk()->assertSee('Montag')->assertSee('Freitag')->assertDontSee('type="time"', false)->assertDontSee('time_mode')->assertDontSee('Ein guter Plan');
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Gemeinsam gut betreut')->assertDontSee('Mit Ruhe. Mit Überblick.');
});

it('transfers a child through system administration without exposing the previous family', function () {
    $data = groupedCareScenario();
    $child = $data['own']['child'];
    $oldContact = $data['own']['contact'];
    app(CareCommunicationService::class)->requestResponses($data['own']['allocation']);
    $token = PersonalAccessToken::firstOrFail();
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('children.update', $child), groupChildPayload($data['year'], $data['foreign']['group'], $data['foreign']['family']))->assertRedirect();

    $this->assertDatabaseHas('waiting_list_entries', ['child_id' => $child->id, 'group_id' => $data['foreign']['group']->id, 'position' => 2]);
    $this->assertDatabaseMissing('waiting_list_entries', ['child_id' => $child->id, 'group_id' => $data['own']['group']->id]);
    expect($token->fresh()->revoked_at)->not->toBeNull();
    $audit = AuditLog::where('event', 'child.saved')->latest('id')->firstOrFail();
    expect(json_encode($audit->data))->not->toContain($oldContact->email);

    $service = app(EmergencyAllocationService::class);
    $service->close($data['own']['allocation']);
    expect($service->propose($data['own']['allocation'])['rows'][0]['awarded'])->toBeFalse();
});

it('creates an administrator restricted to the specified group using the command', function () {
    $group = CareGroup::factory()->create();

    $this->artisan('careslot:admin', ['email' => 'group-admin@example.com', '--group' => $group->id, '--name' => 'Gruppenadmin'])
        ->expectsQuestion('Passwort (mindestens 12 Zeichen)', 'test-password-123')
        ->assertSuccessful();

    $admin = User::where('email', 'group-admin@example.com')->sole();
    expect($admin->is_admin)->toBeTrue();
    expect($admin->group_id)->toBe($group->id);
    expect($admin->isSystemAdministrator())->toBeFalse();
});

it('retains the final administrator with overall access', function () {
    $admin = User::factory()->administrator()->create();
    $group = CareGroup::factory()->create();
    $this->actingAs($admin);

    $this->post(route('groups.admins'), ['user_id' => $admin->id, 'group_id' => $group->id])->assertSessionHasErrors(['user_id' => 'Das letzte Konto mit Gesamtzugriff muss erhalten bleiben.']);

    expect($admin->fresh()->isSystemAdministrator())->toBeTrue();
});

it('retains existing positions when a group transfer leaves gaps and no place is awarded', function () {
    $data = groupedCareScenario();
    $entry = $data['own']['child']->waitingListEntry;
    $entry->update(['position' => 2]);
    $service = app(EmergencyAllocationService::class);
    $allocation = $data['own']['allocation'];
    $service->close($allocation);

    $proposal = $service->propose($allocation);
    $service->confirm($allocation, User::factory()->administrator()->for($data['own']['group'], 'group')->create());

    expect($proposal['rows'][0]['new_position'])->toBe(2);
    expect($entry->fresh()->position)->toBe(2);
    $this->assertDatabaseCount('waiting_list_movements', 0);
});

it('preserves confirmed historical results when migrating removed child hours', function () {
    [$year, $children, $confirmed] = careScenario(['always']);
    $service = app(EmergencyAllocationService::class);
    $service->close($confirmed);
    $service->propose($confirmed);
    $service->confirm($confirmed, User::factory()->administrator()->create());
    $result = $confirmed->results()->firstOrFail();
    $historicalSnapshot = [...$result->snapshot, 'resolution' => ['starts_at' => '12:30', 'ends_at' => '16:00']];
    $result->update(['snapshot' => $historicalSnapshot]);
    [$otherYear, $otherChildren, $proposed] = careScenario(['always']);
    $service->close($proposed);
    $service->propose($proposed);
    $oldProposal = $proposed->fresh()->proposal;
    $response = $proposed->responses()->firstOrFail();
    $legacy = $response->claim_snapshot;
    $legacy['status'] = 'partial';
    $legacy['rules'][0]['time_mode'] = 'from';
    $legacy['rules'][0]['starts_at'] = '12:30';
    $legacy['rules'][0]['ends_at'] = null;
    $response->update(['claim_snapshot' => $legacy]);
    Schema::table('regular_care_assignments', function (Blueprint $table): void {
        $table->string('time_mode')->nullable();
        $table->time('starts_at')->nullable();
        $table->time('ends_at')->nullable();
    });
    Schema::table('emergency_responses', fn (Blueprint $table) => $table->json('time_resolution')->nullable());
    $migration = require database_path('migrations/2026_10_29_211538_remove_individual_care_times.php');

    $migration->up();

    expect($confirmed->fresh()->status)->toBe('confirmed');
    expect($result->fresh()->snapshot)->toBe($historicalSnapshot);
    expect($proposed->fresh()->status)->toBe('closed');
    expect($proposed->fresh()->proposal)->toBeNull();
    expect($response->fresh()->claim_snapshot['status'])->toBe('full');
    expect($response->fresh()->claim_snapshot['rules'][0])->not->toHaveKeys(['time_mode', 'starts_at', 'ends_at']);
    $audit = AuditLog::where('event', 'allocation.proposal_discarded_after_time_removal')->firstOrFail();
    expect($audit->data['proposal'])->toBe($oldProposal);
});

it('creates a group administrator from the management interface', function () {
    $group = CareGroup::factory()->create();
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('groups.admins.store'), ['name' => 'Neue Gruppenleitung', 'email' => 'new-admin@example.com', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123', 'group_id' => $group->id])->assertRedirect();

    $user = User::where('email', 'new-admin@example.com')->sole();
    expect($user->managesGroup($group->id))->toBeTrue();
    expect($user->isSystemAdministrator())->toBeFalse();
    expect(Hash::check('test-password-123', $user->password))->toBeTrue();
    expect(AuditLog::where('event', 'group.administrator_created')->firstOrFail()->data)->not->toHaveKey('password');
});

it('rejects administrator creation by a group administrator', function () {
    $group = CareGroup::factory()->create();
    $this->actingAs(User::factory()->administrator()->for($group, 'group')->create());

    $this->post(route('groups.admins.store'), ['name' => 'Unauthorized', 'email' => 'blocked@example.com', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123', 'group_id' => $group->id])->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
});

it('starts without any automatically created group', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('care_groups', 0);
    $this->assertDatabaseCount('group_years', 0);
});

it('renders the empty administration before a group or year has been created', function () {
    $this->actingAs(User::factory()->administrator()->create());

    foreach (['dashboard', 'groups.index', 'years.index', 'children.index', 'children.create', 'days.create', 'waiting-list'] as $route) {
        $this->get(route($route))->assertOk();
    }
    Livewire::test('waiting-list')->assertSet('groupId', '')->assertSet('yearId', '')->assertStatus(200);

    $this->assertDatabaseCount('care_groups', 0);
});

it('creates independently named example groups only when explicitly seeded', function () {
    $this->seed(CareGroupSeeder::class);
    $this->seed(CareGroupSeeder::class);

    expect(CareGroup::orderBy('name')->pluck('name')->all())->toBe(['Bärengruppe', 'Fuchsgruppe']);
});
