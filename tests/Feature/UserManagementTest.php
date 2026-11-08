<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('requires authentication to view and change user accounts', function () {
    $user = User::factory()->create();

    $this->get(route('users.edit', $user))->assertRedirectToRoute('login');
    $this->put(route('users.update', $user), ['name' => 'Changed', 'email' => 'changed@example.com'])->assertRedirectToRoute('login');
    $this->put(route('users.password.update', $user), ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertRedirectToRoute('login');

    expect($user->fresh()->name)->toBe($user->name);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 0);
});

it('forbids user management for accounts without global access', function (string $role) {
    $user = User::factory()->administrator()->create();
    $actor = $role === 'group'
        ? User::factory()->administrator()->for(CareGroup::factory(), 'group')->create()
        : User::factory()->create();
    $this->actingAs($actor);

    $this->get(route('users.edit', $user))->assertForbidden();
    $this->put(route('users.update', $user), ['name' => 'Changed', 'email' => 'changed@example.com'])->assertForbidden();
    $this->put(route('users.password.update', $user), ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertForbidden();

    expect($user->fresh()->name)->toBe($user->name);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['group administrator' => 'group', 'regular user' => 'user']);

it('updates user details without changing their permissions or password', function () {
    $administrator = User::factory()->administrator()->create();
    $group = CareGroup::factory()->create();
    $user = User::factory()->for($group, 'group')->create();
    $this->actingAs($administrator);

    $this->put(route('users.update', $user), ['name' => 'Neuer Name', 'email' => 'updated@example.com', 'is_admin' => true, 'group_id' => null, 'password' => 'unexpected-password'])
        ->assertRedirectToRoute('users.edit', $user);

    $saved = $user->fresh();
    expect($saved->name)->toBe('Neuer Name');
    expect($saved->email)->toBe('updated@example.com');
    expect($saved->email_verified_at)->toBeNull();
    expect($saved->is_admin)->toBeFalse();
    expect($saved->group_id)->toBe($group->id);
    expect($saved->password)->toBe($user->password);
    $this->assertDatabaseHas('audit_logs', ['event' => 'user.updated', 'user_id' => $administrator->id, 'subject_id' => $user->id]);
    $log = AuditLog::where('event', 'user.updated')->sole();
    expect($log->data)->toBe(['before' => ['name' => $user->name, 'email' => $user->email], 'after' => ['name' => 'Neuer Name', 'email' => 'updated@example.com']]);
});

it('keeps email verification when the email address is unchanged and allows editing oneself', function () {
    $administrator = User::factory()->administrator()->create();
    $this->actingAs($administrator);

    $this->put(route('users.update', $administrator), ['name' => 'Eigener Name', 'email' => $administrator->email])->assertRedirectToRoute('users.edit', $administrator);

    expect($administrator->fresh()->email_verified_at->equalTo($administrator->email_verified_at))->toBeTrue();
    expect($administrator->fresh()->isSystemAdministrator())->toBeTrue();
});

it('rejects invalid user details without changing the account', function (array $data, string $field) {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    User::factory()->create(['email' => 'taken@example.com']);
    $this->actingAs($administrator);

    $this->put(route('users.update', $user), $data)->assertSessionHasErrors($field);

    expect($user->fresh()->name)->toBe($user->name);
    expect($user->fresh()->email)->toBe($user->email);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'missing name' => [['email' => 'new@example.com'], 'name'],
    'name too long' => [['name' => str_repeat('A', 151), 'email' => 'new@example.com'], 'name'],
    'missing email' => [['name' => 'New Name'], 'email'],
    'invalid email' => [['name' => 'New Name', 'email' => 'invalid'], 'email'],
    'duplicate email' => [['name' => 'New Name', 'email' => 'taken@example.com'], 'email'],
    'email too long' => [['name' => 'New Name', 'email' => str_repeat('a', 244).'@example.com'], 'email'],
]);

it('changes a password without exposing it in audit logs or responses', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->administrator()->for(CareGroup::factory(), 'group')->create();
    $this->actingAs($administrator);

    $this->put(route('users.password.update', $user), ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123', 'is_admin' => false, 'name' => 'Unexpected'])
        ->assertRedirectToRoute('users.edit', $user);

    $saved = $user->fresh();
    expect(Hash::check('new-password-123', $saved->password))->toBeTrue();
    expect($saved->password)->not->toBe('new-password-123');
    expect($saved->remember_token)->not->toBe($user->remember_token);
    expect($saved->is_admin)->toBeTrue();
    expect($saved->name)->toBe($user->name);
    $log = AuditLog::where('event', 'user.password_changed')->sole();
    expect($log->user_id)->toBe($administrator->id);
    expect($log->subject_id)->toBe($user->id);
    expect($log->data)->toBe([]);
    $this->get(route('users.edit', $user))->assertOk()->assertDontSee('new-password-123')->assertDontSee($saved->password);
});

it('rejects invalid passwords and does not flash password fields', function (array $data) {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $this->actingAs($administrator);

    $this->put(route('users.password.update', $user), $data)->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.password_confirmation');

    expect($user->fresh()->password)->toBe($user->password);
    expect($user->fresh()->remember_token)->toBe($user->remember_token);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([
    'missing' => [[]],
    'short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'unconfirmed' => [['password' => 'new-password-123']],
    'mismatched' => [['password' => 'new-password-123', 'password_confirmation' => 'other-password-123']],
]);

it('returns not found when editing a missing user', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create();
    $user->delete();
    $this->actingAs($administrator);

    $this->get(route('users.edit', $user->id))->assertNotFound();
    $this->put(route('users.update', $user->id), [])->assertNotFound();
    $this->put(route('users.password.update', $user->id), [])->assertNotFound();
});

it('escapes user details and restores invalid form input', function () {
    $administrator = User::factory()->administrator()->create();
    $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
    $this->actingAs($administrator);

    $this->get(route('users.edit', $user))->assertOk()->assertSee($user->name)->assertDontSee($user->name, false)->assertSee('id="passwort"', false);
    $this->withSession(['_old_input' => ['name' => 'Korrigierter Name', 'email' => 'invalid']])->get(route('users.edit', $user))->assertOk()->assertSee('value="Korrigierter Name"', false)->assertSee('value="invalid"', false);
});
