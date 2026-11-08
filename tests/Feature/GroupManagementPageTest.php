<?php

use App\Models\CareGroup;
use App\Models\User;
use Dom\HTMLDocument;
use Dom\XPath;

it('groups accounts under overall administration and their respective groups with actions in each row', function () {
    $administrator = User::factory()->administrator()->create(['name' => 'Global Admin']);
    $group = CareGroup::factory()->create(['name' => 'Fuchsgruppe']);
    $groupAdmin = User::factory()->administrator()->for($group, 'group')->create(['name' => 'Gruppenleitung']);
    $groupUser = User::factory()->for($group, 'group')->create(['name' => 'Gruppenbenutzer']);
    $unassigned = User::factory()->create(['name' => 'Ohne Gruppe']);
    $this->actingAs($administrator);

    $page = $this->get(route('groups.index'));

    $page->assertOk()->assertSeeInOrder(['Gesamtverwaltung', 'Global Admin', 'Fuchsgruppe', 'Gruppenbenutzer', 'Gruppenleitung', 'Ohne Gruppenzuordnung', 'Ohne Gruppe']);
    $document = HTMLDocument::createFromString($page->getContent(), LIBXML_NOERROR);
    $fields = new XPath($document);
    $fields->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
    foreach ([$administrator, $groupAdmin, $groupUser, $unassigned] as $user) {
        $row = '//html:article[@aria-label="'.$user->name.'"]';
        expect($fields->evaluate('count('.$row.')'))->toBe(1.0);
        expect($fields->evaluate('string('.$row.'//html:a[contains(@href, "/bearbeiten")][1]/@href)'))->toBe(route('users.edit', $user));
        expect($fields->evaluate('count('.$row.'//html:a[contains(@href, "#passwort")])'))->toBe(0.0);
        expect($fields->evaluate('string('.$row.'//html:button[@aria-haspopup="dialog"]/@aria-controls)'))->toBe('assign-user-modal-'.$user->id);
        $template = $fields->query($row.'//html:template[@x-teleport="body"]')->item(0);
        $modalDocument = HTMLDocument::createFromString($template->innerHTML, LIBXML_NOERROR);
        $modal = new XPath($modalDocument);
        $modal->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
        expect($modal->evaluate('count(//html:div[@id="assign-user-modal-'.$user->id.'" and @role="dialog"])'))->toBe(1.0);
        expect($modal->evaluate('string(//html:form[@action="'.route('groups.admins').'"]//html:input[@name="user_id"]/@value)'))->toBe((string) $user->id);
    }
    expect($fields->evaluate('string(//html:section[@aria-label="Fuchsgruppe"]//html:article[@aria-label="Gruppenleitung"]//html:form[contains(@action, "/impersonation/")]/@action)'))->toBe(route('impersonation.store', $groupAdmin));
    expect($fields->evaluate('count(//html:aside[@aria-label="Gruppen und Admins erstellen"]//html:form)'))->toBe(2.0);
    expect($fields->evaluate('count(//html:aside[@aria-label="Gruppen und Admins erstellen"]//html:article)'))->toBe(0.0);
});

it('creates an administrator with overall access without requiring a group', function () {
    $this->actingAs(User::factory()->administrator()->create());

    $this->post(route('groups.admins.store'), ['name' => 'Neue Gesamtverwaltung', 'email' => 'global@example.com', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123', 'group_id' => ''])->assertRedirect();

    $user = User::where('email', 'global@example.com')->sole();
    expect($user->isSystemAdministrator())->toBeTrue();
    $this->get(route('groups.index'))->assertOk()->assertSee('Neue Gesamtverwaltung');
});

it('moves an account to the chosen group from its row', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $group = CareGroup::factory()->create(['name' => 'Zielgruppe']);
    $this->actingAs($administrator);

    $this->post(route('groups.admins'), ['user_id' => $user->id, 'group_id' => $group->id])->assertRedirect();

    expect($user->fresh()->managesGroup($group->id))->toBeTrue();
    $page = $this->get(route('groups.index'));
    $page->assertOk()->assertSee($user->name)->assertDontSee('Ohne Gruppenzuordnung');
    $document = HTMLDocument::createFromString($page->getContent(), LIBXML_NOERROR);
    $fields = new XPath($document);
    $fields->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
    expect($fields->evaluate('count(//html:section[@aria-label="Zielgruppe"]//html:article)'))->toBe(1.0);
});
