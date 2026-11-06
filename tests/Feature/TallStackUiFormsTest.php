<?php

use App\Models\CareGroup;
use App\Models\EmergencyResponse;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Dom\HTMLDocument;
use Dom\XPath;

it('restores child form values and selections without rendering input as markup', function () {
    $otherGroup = CareGroup::factory()->create();
    [$year, $children] = careScenario(['never']);
    $child = $children->first();
    $this->actingAs(User::factory()->administrator()->create());

    $page = $this->withSession(['_old_input' => ['first_name' => 'Mila "<script>"', 'group_id' => $otherGroup->id]])
        ->get(route('children.edit', $child));

    $page->assertOk()->assertDontSee('<script>"', false);
    $document = HTMLDocument::createFromString($page->getContent(), LIBXML_NOERROR);
    $fields = new XPath($document);
    $fields->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
    expect($fields->evaluate('string(//html:input[@name="first_name"]/@value)'))->toBe('Mila "<script>"');
    expect($fields->evaluate('string(//html:select[@name="group_id"]/html:option[@selected]/@value)'))->toBe((string) $otherGroup->id);
    expect($fields->evaluate('string(//html:select[@name="kita_year_id"]/html:option[@selected]/@value)'))->toBe((string) $year->id);
    expect($fields->evaluate('string(//html:input[@name="rules[0][valid_from]"]/@value)'))->not->toBeEmpty();
});

it('shows saved parent choices and gives unsaved form values precedence', function (array $oldInput, string $regularValue) {
    $response = EmergencyResponse::factory()->create([
        'claim_snapshot' => ['status' => 'full', 'rules' => [], 'explanation' => 'Regulärer Anspruch.'],
        'regular_needed' => false,
    ]);
    $contact = ParentContact::factory()->for($response->child->family)->create();
    $token = bin2hex(random_bytes(32));
    PersonalAccessToken::factory()->for($response, 'response')->for($contact)->create(['token_hash' => hash('sha256', $token)]);

    $page = $this->withSession(['_old_input' => $oldInput])->get(route('parent.show', $token));

    $page->assertOk();
    $document = HTMLDocument::createFromString($page->getContent(), LIBXML_NOERROR);
    $fields = new XPath($document);
    $fields->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
    expect($fields->evaluate('string(//html:input[@name="regular_needed" and @checked]/@value)'))->toBe($regularValue);
    expect($fields->evaluate('string(//html:input[@name="waiting_needed"]/@value)'))->toBe('0');
    expect($fields->evaluate('count(//html:label[@for="regular-needed-yes"])'))->toBe(1.0);
})->with([
    'saved choices' => [[], '0'],
    'unsaved choices' => [['regular_needed' => '1'], '1'],
]);
