<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows only global administrators to start impersonation', function (string $role, bool $allowed) {
    $user = match ($role) {
        'global' => User::factory()->administrator()->create(),
        'group' => User::factory()->administrator()->for(CareGroup::factory(), 'group')->create(),
        default => User::factory()->create(),
    };

    expect(Gate::forUser($user)->allows('impersonate'))->toBe($allowed);
})->with([
    'global administrator' => ['global', true],
    'group administrator' => ['group', false],
    'regular user' => ['user', false],
]);

it('requires authentication for all impersonation endpoints', function () {
    $user = User::factory()->create();

    $this->post(route('impersonation.store', $user))->assertRedirectToRoute('login');
    $this->post(route('impersonation.destroy'))->assertRedirectToRoute('login');
    $this->get(route('impersonation.show'))->assertRedirectToRoute('login');

    $this->assertDatabaseCount('audit_logs', 0);
});

it('forbids group administrators from impersonating another user', function () {
    $administrator = User::factory()->administrator()->for(CareGroup::factory(), 'group')->create();
    $user = User::factory()->administrator()->create();
    $this->actingAs($administrator);

    $this->post(route('impersonation.store', $user))->assertForbidden()->assertSessionMissing('impersonator_id');

    $this->assertAuthenticatedAs($administrator);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('switches to any other account and records the original administrator', function (string $role, string $destination) {
    $administrator = User::factory()->administrator()->create();
    $user = match ($role) {
        'global' => User::factory()->administrator()->create(),
        'group' => User::factory()->administrator()->for(CareGroup::factory(), 'group')->create(),
        default => User::factory()->create(),
    };
    $rememberToken = $user->remember_token;
    $this->actingAs($administrator)->withSession(['private_admin_state' => 'secret', 'auth.password_confirmed_at' => time()]);
    $sessionId = session()->getId();
    $csrfToken = session()->token();

    $this->post(route('impersonation.store', $user))
        ->assertRedirectToRoute($destination)
        ->assertSessionHas('impersonator_id', $administrator->id)
        ->assertSessionMissing('private_admin_state')
        ->assertSessionMissing('auth.password_confirmed_at');

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionId);
    expect(session()->token())->not->toBe($csrfToken);
    expect($user->fresh()->remember_token)->toBe($rememberToken);
    $this->assertDatabaseHas('audit_logs', ['event' => 'impersonation.started', 'user_id' => $administrator->id, 'subject_type' => User::class, 'subject_id' => $user->id]);
    $this->get(route($destination))->assertOk()->assertSee('Impersonation aktiv')->assertSee('Zum eigenen Konto zurück');
})->with([
    'regular user' => ['user', 'impersonation.show'],
    'group administrator' => ['group', 'dashboard'],
    'global administrator' => ['global', 'dashboard'],
]);

it('applies the target account permissions to administrative requests', function () {
    $administrator = User::factory()->administrator()->create();
    $group = CareGroup::factory()->create();
    $user = User::factory()->administrator()->for($group, 'group')->create();
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('dashboard');

    $this->get(route('groups.index'))->assertForbidden();
    $this->post(route('groups.store'), ['name' => 'Forbidden group'])->assertForbidden();
    $this->assertDatabaseMissing('care_groups', ['name' => 'Forbidden group']);
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Als Nutzer anmelden');
});

it('keeps the original administrator identifiable in audit entries during impersonation', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->administrator()->create();
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('dashboard');

    $this->post(route('groups.store'), ['name' => 'Neue Gruppe'])->assertRedirect();

    $log = AuditLog::where('event', 'group.created')->sole();
    expect($log->user_id)->toBe($user->id);
    expect($log->data['impersonator_id'])->toBe($administrator->id);
});

it('does not grant administration access to an impersonated regular user', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('impersonation.show');

    $this->get(route('dashboard'))->assertForbidden();
    $this->get(route('impersonation.show'))->assertOk()->assertSee('Dieses Konto hat keinen Zugriff auf die Verwaltung.');
});

it('forbids impersonation of the current account', function () {
    $administrator = User::factory()->administrator()->create();
    $this->actingAs($administrator);

    $this->post(route('impersonation.store', $administrator))->assertForbidden()->assertSessionMissing('impersonator_id');

    $this->assertAuthenticatedAs($administrator);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('returns not found for a missing target account', function () {
    $administrator = User::factory()->administrator()->create();
    $deleted = User::factory()->create();
    $deleted->delete();
    $this->actingAs($administrator);

    $this->post(route('impersonation.store', $deleted->id))->assertNotFound()->assertSessionMissing('impersonator_id');

    $this->assertAuthenticatedAs($administrator);
    $this->assertDatabaseCount('audit_logs', 0);
});

it('prevents nested impersonation even when the target is a global administrator', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->administrator()->create();
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('dashboard');

    $this->post(route('impersonation.store', $administrator))->assertForbidden()->assertSessionHas('impersonator_id', $administrator->id);

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->get(route('groups.index'))->assertOk()->assertDontSee('Als Nutzer anmelden');
});

it('returns to the original administrator from an account without admin permissions', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $rememberToken = $user->remember_token;
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('impersonation.show');
    $this->withSession(['private_target_state' => 'secret']);
    $sessionId = session()->getId();
    $csrfToken = session()->token();

    $this->post(route('impersonation.destroy'), ['impersonator_id' => $user->id])
        ->assertRedirectToRoute('groups.index')
        ->assertSessionMissing('impersonator_id')
        ->assertSessionMissing('private_target_state');

    $this->assertAuthenticatedAs($administrator);
    expect(session()->getId())->not->toBe($sessionId);
    expect(session()->token())->not->toBe($csrfToken);
    expect($user->fresh()->remember_token)->toBe($rememberToken);
    $this->assertDatabaseHas('audit_logs', ['event' => 'impersonation.ended', 'user_id' => $administrator->id, 'subject_id' => $user->id]);
    $this->get(route('groups.index'))->assertOk()->assertDontSee('Impersonation aktiv');
});

it('forbids returning to another account without an impersonation session', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('impersonation.destroy'), ['impersonator_id' => User::factory()->administrator()->create()->id])->assertForbidden();
    $this->get(route('impersonation.show'))->assertForbidden();

    $this->assertAuthenticatedAs($user);
});

it('logs out instead of restoring an administrator whose global access was removed', function (string $change) {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('impersonation.show');
    match ($change) {
        'group' => $administrator->forceFill(['group_id' => CareGroup::factory()->create()->id])->save(),
        default => $administrator->forceFill(['is_admin' => false])->save(),
    };

    $this->post(route('impersonation.destroy'))->assertRedirectToRoute('login')->assertSessionMissing('impersonator_id')->assertSessionHasErrors('email');

    $this->assertGuest();
})->with(['demoted' => 'demote', 'restricted to a group' => 'group']);

it('logs out when the original administrator no longer exists', function () {
    $administrator = User::factory()->administrator()->create();
    $administrator->delete();
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['impersonator_id' => $administrator->id]);

    $this->post(route('impersonation.destroy'))->assertRedirectToRoute('login')->assertSessionMissing('impersonator_id')->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs out safely when the impersonation session contains an invalid administrator identifier', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['impersonator_id' => [$user->id]]);

    $this->post(route('impersonation.destroy'))->assertRedirectToRoute('login')->assertSessionMissing('impersonator_id')->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('clears impersonation on logout without changing the target remember token', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $rememberToken = $user->remember_token;
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('impersonation.show');

    $this->post(route('logout'))->assertRedirectToRoute('login')->assertSessionMissing('impersonator_id');

    $this->assertGuest();
    expect($user->fresh()->remember_token)->toBe($rememberToken);
    $this->assertDatabaseHas('audit_logs', ['event' => 'impersonation.ended', 'subject_id' => $user->id]);
});

it('clears impersonation when signing in with credentials for another account', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $other = User::factory()->administrator()->create();
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('impersonation.show');

    $this->post(route('login.store'), ['email' => $other->email, 'password' => 'password'])->assertRedirectToRoute('dashboard')->assertSessionMissing('impersonator_id');

    $this->assertAuthenticatedAs($other);
    $this->post(route('impersonation.destroy'))->assertForbidden();
});

it('offers impersonation for every other account and escapes account names', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs($administrator);

    $this->get(route('groups.index'))->assertOk()
        ->assertSee(route('impersonation.store', $user), false)
        ->assertDontSee(route('impersonation.store', $administrator), false)
        ->assertSee($user->name)
        ->assertDontSee($user->name, false);
});

it('escapes the impersonated identity in the status banner', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs($administrator)->post(route('impersonation.store', $user))->assertRedirectToRoute('impersonation.show');

    $this->get(route('impersonation.show'))->assertOk()->assertSee($user->name)->assertDontSee($user->name, false);
});
