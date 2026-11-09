<?php

use App\Models\AuditLog;
use App\Models\CareGroup;
use App\Models\Child;
use App\Models\GroupYear;
use App\Models\KitaYear;
use App\Models\User;
use App\Services\EmergencyAllocationService;
use App\Services\WaitingListService;
use Livewire\Livewire;

it('lets group administrators reorder their list regardless of the procedure or year status', function (string $status, bool $drag) {
    [$year, $children, $allocation] = careScenario(['never', 'never', 'never']);
    $admin = User::factory()->administrator()->for($children[0]->group, 'group')->create();
    $service = app(EmergencyAllocationService::class);
    if (in_array($status, ['closed', 'confirmed'], true)) {
        $service->close($allocation);
    }
    if ($status === 'confirmed') {
        $service->propose($allocation);
        $service->confirm($allocation, $admin);
    }
    if ($status === 'archived') {
        $year->update(['status' => 'archived']);
    }
    $list = GroupYear::where('kita_year_id', $year->id)->where('group_id', $admin->group_id)->sole();
    $revision = $list->revision;
    $entry = $children[0]->waitingListEntry;
    $this->actingAs($admin);

    $component = Livewire::test('waiting-list')->assertDontSee('>Position ändern<', false);
    if ($drag) {
        $component->call('sortEntry', $entry->id, 2);
    } else {
        $component->call('editPosition', $entry->id)->assertSet('position', '1')
            ->set('position', '3')->call('savePosition');
    }
    $component->assertHasNoErrors()->assertSet('editingEntryId', null)->assertSee('Reihenfolge gespeichert.');

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe([$children[1]->id, $children[2]->id, $children[0]->id]);
    expect($list->fresh()->revision)->toBe($revision + 1);
    $audit = AuditLog::where('event', 'waiting_list.reordered')->sole();
    expect($audit->user_id)->toBe($admin->id);
    expect($audit->group_id)->toBe($admin->group_id);
    expect($audit->data['before'])->toBe([$children[0]->id => 1, $children[1]->id => 2, $children[2]->id => 3]);
    expect($audit->data['after'])->toBe([$children[1]->id => 1, $children[2]->id => 2, $children[0]->id => 3]);
    $this->assertDatabaseCount('waiting_list_movements', 0);
})->with(['responses', 'closed', 'confirmed', 'archived'])->with(['manual' => false, 'drag' => true]);

it('lets global administrators edit each group independently', function () {
    [$year, $children] = careScenario(['never', 'never']);
    $otherGroup = CareGroup::factory()->create();
    $otherChildren = Child::factory()->for($year)->for($otherGroup, 'group')->count(2)->sequence(['last_name' => 'A'], ['last_name' => 'B'])->create();
    app(WaitingListService::class)->initialize($year, $otherGroup);
    $this->actingAs(User::factory()->administrator()->create());
    $component = Livewire::test('waiting-list')->set('groupId', (string) $otherGroup->id);

    $component->call('editPosition', $otherChildren[1]->waitingListEntry->id)->set('position', '1')->call('savePosition')->assertHasNoErrors();

    expect($year->waitingListEntries()->where('group_id', $otherGroup->id)->orderBy('position')->pluck('child_id')->all())->toBe([$otherChildren[1]->id, $otherChildren[0]->id]);
    expect($year->waitingListEntries()->where('group_id', $children[0]->group_id)->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());

    $component->set('groupId', (string) $children[0]->group_id)->call('editPosition', $children[1]->waitingListEntry->id)->set('position', '1')->call('savePosition')->assertHasNoErrors();

    expect($year->waitingListEntries()->where('group_id', $children[0]->group_id)->orderBy('position')->pluck('child_id')->all())->toBe([$children[1]->id, $children[0]->id]);
    expect(AuditLog::where('event', 'waiting_list.reordered')->count())->toBe(2);
});

it('rejects entries outside the selected group or year', function (bool $differentYear) {
    [$year, $children] = careScenario(['never', 'never']);
    $otherYear = $differentYear ? KitaYear::factory()->create() : $year;
    $otherGroup = $differentYear ? $children[0]->group : CareGroup::factory()->create();
    $otherChild = Child::factory()->for($otherYear)->for($otherGroup, 'group')->create();
    app(WaitingListService::class)->initialize($otherYear, $otherGroup);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    Livewire::test('waiting-list')->set('yearId', (string) $year->id)->call('editPosition', $otherChild->waitingListEntry->id)->assertStatus(404);

    expect($otherChild->waitingListEntry->fresh()->position)->toBe(1);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
})->with(['other group' => false, 'other year' => true]);

it('rechecks administrator privileges when saving an already opened editor', function (bool $removeAdministratorRole) {
    [$year, $children] = careScenario(['never', 'never']);
    $admin = User::factory()->administrator()->for($children[0]->group, 'group')->create();
    $this->actingAs($admin);
    $component = Livewire::test('waiting-list')->call('editPosition', $children[0]->waitingListEntry->id)->set('position', '2');
    $admin->forceFill($removeAdministratorRole ? ['is_admin' => false] : ['group_id' => CareGroup::factory()->create()->id])->save();
    $this->actingAs($admin->fresh());

    $component->call('savePosition')->assertStatus($removeAdministratorRole ? 403 : 404);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
})->with(['role revoked' => true, 'group changed' => false]);

it('rejects invalid positions without changing the list or revision', function (string $position, string $message) {
    [$year, $children] = careScenario(['never', 'never', 'never']);
    $list = GroupYear::where('kita_year_id', $year->id)->sole();
    $revision = $list->revision;
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    Livewire::test('waiting-list')->call('editPosition', $children[0]->waitingListEntry->id)
        ->set('position', $position)->call('savePosition')->assertHasErrors('position')->assertSee($message);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    expect($list->fresh()->revision)->toBe($revision);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
})->with([
    'empty' => ['', 'Bitte eine neue Position angeben.'],
    'text' => ['first', 'Die Position muss eine ganze Zahl sein.'],
    'fraction' => ['1.5', 'Die Position muss eine ganze Zahl sein.'],
    'zero' => ['0', 'Die Position muss mindestens 1 sein.'],
    'negative' => ['-1', 'Die Position muss mindestens 1 sein.'],
    'past the end' => ['4', 'Die Position muss zwischen 1 und 3 liegen.'],
]);

it('moves within the entire list while searching and keeps inactive entries', function () {
    [$year, $children] = careScenario(['never', 'never', 'never']);
    $children[1]->update(['active' => false]);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    Livewire::test('waiting-list')->set('search', 'Anna')->assertDontSee('Ben Beispiel')->assertDontSee('Clara Beispiel')
        ->call('editPosition', $children[0]->waitingListEntry->id)->set('position', '3')->call('savePosition')->assertHasNoErrors();

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe([$children[1]->id, $children[2]->id, $children[0]->id]);
    expect($year->waitingListEntries()->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);
});

it('does not change the revision when saving an unchanged position', function () {
    [$year, $children] = careScenario(['never', 'never']);
    $list = GroupYear::where('kita_year_id', $year->id)->sole();
    $revision = $list->revision;
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->call('editPosition', $children[0]->waitingListEntry->id)->call('savePosition')->assertHasNoErrors();

    expect($list->fresh()->revision)->toBe($revision);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
});

it('cancels pending changes when selecting another group or year', function (string $field) {
    [$year, $children] = careScenario(['never', 'never']);
    $selection = $field === 'groupId' ? CareGroup::factory()->create()->id : KitaYear::factory()->create()->id;
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->set('groupId', (string) $children[0]->group_id)->set('yearId', (string) $year->id)
        ->call('editPosition', $children[0]->waitingListEntry->id)->set('position', '2')
        ->set($field, (string) $selection)->assertSet('editingEntryId', null)->assertSet('position', '');

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
})->with(['groupId', 'yearId']);

it('rejects confirmation of a proposal calculated before a reorder', function (bool $drag) {
    [$year, $children, $allocation] = careScenario(['never', 'never', 'never']);
    $allocation->responses()->update(['waiting_needed' => true]);
    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $service->propose($allocation);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());
    $component = Livewire::test('waiting-list');
    if ($drag) {
        $component->call('sortEntry', $children[2]->waitingListEntry->id, 0);
    } else {
        $component->call('editPosition', $children[2]->waitingListEntry->id)->set('position', '1')->call('savePosition');
    }
    $component->assertHasNoErrors();

    $this->post(route('allocations.action', $allocation), ['action' => 'confirm', 'confirmation' => 1, 'proposal_hash' => $allocation->fresh()->proposalHash()])
        ->assertSessionHasErrors(['allocation' => 'Stammdaten oder Warteliste haben sich geändert. Vorschlag verwerfen und neu berechnen.']);

    $this->assertDatabaseCount('allocation_results', 0);
    $this->assertDatabaseCount('waiting_list_movements', 0);
    $service->discard($allocation);
    $proposal = $service->propose($allocation);
    expect(collect($proposal['rows'])->where('awarded', true)->pluck('child_id')->all())->toBe([$children[2]->id]);
})->with(['manual' => false, 'drag' => true]);

it('renumbers gaps when moving an entry and preserves every child', function () {
    [$year, $children] = careScenario(['never', 'never', 'never']);
    $children[2]->waitingListEntry->update(['position' => 7]);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());

    Livewire::test('waiting-list')->call('editPosition', $children[2]->waitingListEntry->id)->assertSet('position', '3')
        ->set('position', '1')->call('savePosition')->assertHasNoErrors();

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe([$children[2]->id, $children[0]->id, $children[1]->id]);
    expect($year->waitingListEntries()->orderBy('position')->pluck('position')->all())->toBe([1, 2, 3]);
});

it('leaves the list unchanged when an administrator cancels editing', function () {
    [$year, $children] = careScenario(['never', 'never']);
    $this->actingAs(User::factory()->administrator()->create());

    Livewire::test('waiting-list')->call('editPosition', $children[0]->waitingListEntry->id)->set('position', '2')
        ->call('cancelEdit')->assertSet('editingEntryId', null)->assertSet('positionSaved', false);

    expect($year->waitingListEntries()->orderBy('position')->pluck('child_id')->all())->toBe($children->pluck('id')->all());
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
});

it('refuses a save when the edited entry has been removed in the meantime', function () {
    [$year, $children] = careScenario(['never', 'never']);
    $this->actingAs(User::factory()->administrator()->for($children[0]->group, 'group')->create());
    $entry = $children[0]->waitingListEntry;
    $component = Livewire::test('waiting-list')->call('editPosition', $entry->id)->set('position', '2');
    $entry->delete();

    $component->call('savePosition')->assertStatus(404);

    $this->assertDatabaseHas('waiting_list_entries', ['child_id' => $children[1]->id, 'position' => 2]);
    $this->assertDatabaseMissing('audit_logs', ['event' => 'waiting_list.reordered']);
});
