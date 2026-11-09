<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\Child;
use App\Models\GroupYear;
use App\Models\KitaYear;
use App\Models\User;
use App\Services\WaitingListService;
use Livewire\Livewire;

it('persists dragging in both directions after reopening the list', function () {
    [$year, $children] = careScenario(['never', 'never', 'never']);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    Livewire::test('waiting-list')->assertSee('wire:sort="sortEntry"', false)
        ->call('sortEntry', $children[0]->waitingListEntry->id, 2)->assertHasNoErrors()
        ->assertSee('Reihenfolge gespeichert.');

    Livewire::test('waiting-list')->assertSeeInOrder(['Ben Beispiel', 'Clara Beispiel', 'Anna Beispiel'])
        ->call('sortEntry', $children[0]->waitingListEntry->id, 0)->assertHasNoErrors();

    Livewire::test('waiting-list')->assertSeeInOrder(['Anna Beispiel', 'Ben Beispiel', 'Clara Beispiel']);
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    expect(AuditLog::where('event', 'waiting_list.reordered')->count())->toBe(2);
});

it('maps visible drop positions to the full list including inactive and filtered children', function (bool $upward) {
    [$year, $children] = careScenario(['never', 'never', 'never', 'never']);
    $children[0]->update(['last_name' => 'Treffer']);
    $children[2]->update(['last_name' => 'Treffer']);
    $children[1]->update(['active' => false]);
    $children[2]->waitingListEntry->update(['position' => 7]);
    $children[3]->waitingListEntry->update(['position' => 9]);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    Livewire::test('waiting-list')->set('search', 'Treffer')->assertDontSee('Ben Beispiel')->assertDontSee('David Beispiel')
        ->call('sortEntry', $children[$upward ? 2 : 0]->waitingListEntry->id, $upward ? 0 : 1)->assertHasNoErrors()
        ->assertSeeInOrder(['Clara Treffer', 'Anna Treffer']);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($upward
        ? [$children[2]->id, $children[0]->id, $children[1]->id, $children[3]->id]
        : [$children[1]->id, $children[2]->id, $children[0]->id, $children[3]->id]);
    expect($year->waitingListEntries()->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3, 4]);
})->with(['upward' => true, 'downward' => false]);

it('rejects drops outside the visible list without changing its revision', function (int $position) {
    [$year, $children] = careScenario(['never', 'never', 'never']);
    $children[1]->update(['active' => false]);
    $list = GroupYear::where('kita_year_id', $year->id)->sole();
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->call('sortEntry', $children[0]->waitingListEntry->id, $position)
        ->assertHasErrors('position')->assertSet('positionSaved', false)->assertSee('Die Zielposition ist nicht mehr verfügbar.');

    expect($list->fresh()->revision)->toBe($list->revision);
    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
})->with([-1, 2]);

it('rejects dragging hidden children', function (bool $inactive) {
    [$year, $children] = careScenario(['never', 'never']);
    $children[1]->update(['active' => ! $inactive]);
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->set('search', $inactive ? '' : 'Anna')
        ->call('sortEntry', $children[1]->waitingListEntry->id, 0)->assertStatus(404);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
})->with(['inactive' => true, 'filtered' => false]);

it('scopes dragging to the selected group and year even for global administrators', function (bool $differentYear) {
    [$year, $children] = careScenario(['never', 'never']);
    $otherYear = $differentYear ? KitaYear::factory()->create() : $year;
    $otherGroup = $differentYear ? $children[0]->group : CareGroup::factory()->create();
    $otherChildren = Child::factory()->for($otherYear)->for($otherGroup, 'group')->count(2)->create();
    app(WaitingListService::class)->initialize($otherYear, $otherGroup);
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->set('yearId', (string) $year->id)->set('groupId', (string) $children[0]->group_id)
        ->call('sortEntry', $otherChildren[0]->waitingListEntry->id, 1)->assertStatus(404);

    Livewire::test('waiting-list')->set('yearId', (string) $otherYear->id)->set('groupId', (string) $otherGroup->id)
        ->call('sortEntry', $otherChildren[0]->waitingListEntry->id, 1)->assertHasNoErrors();

    expect($otherChildren[0]->waitingListEntry->fresh()->position)->toBe(2);
    expect($year->waitingListEntries()->where('group_id', $children[0]->group_id)->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
})->with(['other group' => false, 'other year' => true]);

it('rechecks administrator and group permissions on every drop', function (bool $removeRole) {
    [$year, $children] = careScenario(['never', 'never']);
    $admin = User::factory()->administrator()->for($children[0]->group, 'group')->create();
    $this->actingAs($admin);
    $component = Livewire::test('waiting-list');
    $admin->forceFill($removeRole ? ['is_admin' => false] : ['group_id' => CareGroup::factory()->create()->id])->save();
    $this->actingAs($admin->fresh());

    $component->call('sortEntry', $children[0]->waitingListEntry->id, 1)->assertStatus($removeRole ? 403 : 404);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
})->with(['role revoked' => true, 'group changed' => false]);

it('leaves an unchanged drop unaudited and cancels an open position editor', function () {
    [$year, $children] = careScenario(['never', 'never']);
    $list = GroupYear::where('kita_year_id', $year->id)->sole();
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->call('editPosition', $children[1]->waitingListEntry->id)->set('position', '1')
        ->call('sortEntry', $children[0]->waitingListEntry->id, 0)->assertHasNoErrors()
        ->assertSet('editingEntryId', null)->assertSet('position', '');

    expect($list->fresh()->revision)->toBe($list->revision);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
});
