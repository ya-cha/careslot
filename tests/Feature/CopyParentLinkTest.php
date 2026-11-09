<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\EmailDelivery;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\CareCommunicationService;

it('copies an existing link from encrypted delivery data without creating or logging a token', function () {
    [$year, $children, $allocation] = careScenario(['never']);
    ParentContact::factory()->for($children[0]->family)->create();
    $communication = app(CareCommunicationService::class);
    $communication->requestResponses($allocation);
    $communication->requestResponses($allocation, true);
    $tokens = PersonalAccessToken::orderBy('id')->get();
    $deliveries = EmailDelivery::orderBy('id')->get();
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());
    $auditCount = AuditLog::count();

    $page = $this->get(route('allocations.show', $allocation))->assertOk()->assertSee('Link kopieren');
    foreach ($tokens as $index => $token) {
        $response = $this->getJson(route('tokens.copy', $token))->assertOk()->assertJsonStructure(['url']);
        expect($response->json('url') === route('parent.show', $deliveries[$index]->payload['token']))->toBeTrue();
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        expect(str_contains($page->getContent(), $deliveries[$index]->payload['token']))->toBeFalse();
        expect(str_contains($deliveries[$index]->getRawOriginal('payload'), $deliveries[$index]->payload['token']))->toBeFalse();
    }
    expect(AuditLog::count())->toBe($auditCount);
    $this->assertDatabaseCount('personal_access_tokens', 2);
});

it('refuses to copy revoked expired or unavailable links', function (string $state) {
    [$year, $children, $allocation] = careScenario(['never']);
    ParentContact::factory()->for($children[0]->family)->create();
    app(CareCommunicationService::class)->requestResponses($allocation);
    $token = PersonalAccessToken::sole();
    match ($state) {
        'revoked' => $token->update(['revoked_at' => now()]),
        'expired' => $token->update(['expires_at' => now()]),
        'unavailable' => EmailDelivery::query()->delete(),
    };
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    $response = $this->getJson(route('tokens.copy', $token))->assertStatus($state === 'unavailable' ? 404 : 422);
    expect($response->json())->not->toHaveKey('url');
    if ($state !== 'unavailable') {
        $this->get(route('allocations.show', $allocation))->assertOk()->assertDontSee('Link kopieren');
    }
})->with(['revoked', 'expired', 'unavailable']);

it('authorizes copying personal links by administrator role and group', function (string $role) {
    [$year, $children, $allocation] = careScenario(['never']);
    ParentContact::factory()->for($children[0]->family)->create();
    app(CareCommunicationService::class)->requestResponses($allocation);
    $token = PersonalAccessToken::sole();
    $admin = match ($role) {
        'foreign' => User::factory()->administrator()->for(CareGroup::factory(), 'group')->create(),
        'parent' => User::factory()->create(),
        'global' => User::factory()->administrator()->create(),
    };
    $this->actingAs($admin);

    $this->getJson(route('tokens.copy', $token))->assertStatus(match ($role) {
        'foreign' => 404, 'parent' => 403, 'global' => 200,
    });
})->with(['foreign', 'parent', 'global']);
