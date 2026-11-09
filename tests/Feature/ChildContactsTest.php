<?php

use App\Models\AuditLog;
use App\Models\Child;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\CareCommunicationService;

function childContactData(Child $child): array
{
    return [
        'group_id' => $child->group_id, 'kita_year_id' => $child->kita_year_id, 'family_id' => $child->family_id,
        'first_name' => 'Geänderter Vorname', 'last_name' => $child->last_name, 'active' => true,
        'rules' => collect(range(1, 5))->map(fn ($weekday) => ['weekday' => $weekday, 'pattern' => 'never', 'valid_from' => '2026-08-01'])->all(),
    ];
}

it('saves child details existing contacts and additions together and revokes corrected contact links', function () {
    [$year, $children, $allocation] = careScenario(['never']);
    $child = $children[0];
    $contact = ParentContact::factory()->for($child->family)->create();
    $unchanged = ParentContact::factory()->for($child->family)->create();
    app(CareCommunicationService::class)->requestResponses($allocation);
    $this->actingAs(User::factory()->administrator()->for($child->group, 'group')->create());

    $this->put(route('children.update', $child), [...childContactData($child), 'contacts' => [
        ['id' => $contact->id, 'name' => 'Neuer Name', 'email' => 'korrigiert@example.com'],
        $unchanged->only('id', 'name', 'email'),
        ['id' => null, 'name' => 'Weiterer Kontakt', 'email' => 'weiterer@example.com'],
        ['id' => null, 'name' => '', 'email' => ''],
    ]])->assertRedirect()->assertSessionHasNoErrors();

    expect($child->fresh()->first_name)->toBe('Geänderter Vorname');
    expect($contact->fresh()->only('name', 'email'))->toBe(['name' => 'Neuer Name', 'email' => 'korrigiert@example.com']);
    expect(PersonalAccessToken::where('parent_contact_id', $contact->id)->sole()->revoked_at)->not->toBeNull();
    expect(PersonalAccessToken::where('parent_contact_id', $unchanged->id)->sole()->revoked_at)->toBeNull();
    $this->assertDatabaseCount('parent_contacts', 3);
    $this->assertDatabaseHas('parent_contacts', ['family_id' => $child->family_id, 'email' => 'weiterer@example.com']);
    expect(AuditLog::where('event', 'contact.updated')->count())->toBe(1);
});

it('rejects contacts belonging to another family without saving any other changes', function (bool $foreignGroup) {
    [$year, $children] = careScenario(['never', 'never']);
    $child = $children[0];
    $foreign = $foreignGroup
        ? ParentContact::factory()->create()
        : ParentContact::factory()->for($children[1]->family)->create();
    $this->actingAs(User::factory()->administrator()->for($child->group, 'group')->create());

    $this->put(route('children.update', $child), [...childContactData($child), 'contacts' => [
        ['id' => $foreign->id, 'name' => 'Fremder Kontakt', 'email' => 'fremd@example.com'],
    ]])->assertSessionHasErrors('contacts.0.id');

    expect($child->fresh()->first_name)->toBe($child->first_name);
    expect($foreign->fresh()->email)->toBe($foreign->email);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'child.saved']);
})->with(['same group' => false, 'foreign group' => true]);

it('validates incomplete duplicate and cleared existing contacts atomically', function (string $case) {
    [$year, $children] = careScenario(['never']);
    $child = $children[0];
    $contact = ParentContact::factory()->for($child->family)->create();
    $other = ParentContact::factory()->for($child->family)->create();
    $rows = match ($case) {
        'incomplete' => [['name' => 'Ohne E-Mail', 'email' => '']],
        'duplicate' => [['id' => $contact->id, 'name' => 'Name', 'email' => $other->email]],
        'cleared' => [['id' => $contact->id, 'name' => '', 'email' => '']],
    };
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('children.update', $child), [...childContactData($child), 'contacts' => $rows])
        ->assertSessionHasErrors('contacts.0.'.($case === 'cleared' ? 'name' : 'email'));

    expect($child->fresh()->first_name)->toBe($child->first_name);
    expect($contact->fresh()->email)->toBe($contact->email);
    $this->assertDatabaseCount('parent_contacts', 2);
})->with(['incomplete', 'duplicate', 'cleared']);

it('allows multiple new contact rows and ignores the trailing empty row', function () {
    [$year, $children] = careScenario(['never']);
    $child = $children[0];
    $this->actingAs(User::factory()->administrator()->create());

    $this->put(route('children.update', $child), [...childContactData($child), 'contacts' => [
        ['id' => '', 'name' => 'Eins', 'email' => 'eins@example.com'],
        ['id' => '', 'name' => 'Zwei', 'email' => 'zwei@example.com'],
        ['id' => '', 'name' => '  ', 'email' => '  '],
    ]])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseCount('parent_contacts', 2);
});

it('shows one shared contact section and one save action', function () {
    [$year, $children] = careScenario(['never']);
    ParentContact::factory()->for($children[0]->family)->create();
    $this->actingAs(User::factory()->administrator()->create());

    $this->get(route('children.edit', $children[0]))->assertOk()->assertSee('Elternkontakte')
        ->assertSee('Änderungen speichern')->assertDontSee('Kontakt speichern')
        ->assertDontSee('Elternkontakte ergänzen')->assertDontSee('Bestehende Elternkontakte bearbeiten');
});

it('keeps larger existing families editable while limiting each batch of additions', function () {
    [$year, $children] = careScenario(['never']);
    $child = $children[0];
    $contacts = ParentContact::factory()->for($child->family)->count(11)->create();
    $this->actingAs(User::factory()->administrator()->create());
    $rows = $contacts->map(fn ($contact) => $contact->only('id', 'name', 'email'))->all();

    $this->put(route('children.update', $child), [...childContactData($child), 'contacts' => $rows])
        ->assertRedirect()->assertSessionHasNoErrors();

    $additions = collect(range(1, 11))->map(fn ($index) => ['name' => 'Neuer Kontakt', 'email' => 'neu'.$index.'@example.com'])->all();
    $this->put(route('children.update', $child), [...childContactData($child), 'contacts' => [...$rows, ...$additions]])
        ->assertSessionHasErrors('contacts');
    $this->assertDatabaseCount('parent_contacts', 11);
});
