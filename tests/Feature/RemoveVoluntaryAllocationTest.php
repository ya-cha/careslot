<?php

use App\Jobs\SendCareEmail;
use App\Models\AllocationResult;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\EmergencyAllocation;
use App\Models\ParentContact;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\WaitingListMovement;
use App\Services\EmergencyAllocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

it('removes old procedures and participation data while keeping master data and waiting positions', function () {
    [$year, $children, $allocation] = careScenario(['never']);
    $child = $children->first();
    $admin = User::factory()->administrator()->create();
    $response = $allocation->responses()->sole();
    $contact = ParentContact::factory()->for($child->family)->create();
    PersonalAccessToken::factory()->for($response, 'response')->for($contact)->create();
    EmailDelivery::factory()->create(['emergency_allocation_id' => $allocation->id, 'parent_contact_id' => $contact->id]);
    AllocationResult::factory()->create(['emergency_allocation_id' => $allocation->id, 'child_id' => $child->id, 'phase' => 3, 'awarded' => true]);
    WaitingListMovement::factory()->create(['emergency_allocation_id' => $allocation->id, 'child_id' => $child->id]);
    EmergencyAllocation::factory()->create(['emergency_day_id' => $allocation->emergency_day_id, 'previous_allocation_id' => $allocation->id]);
    $allocation->update(['status' => 'confirmed', 'proposal' => ['lottery' => ['winners' => [$child->id]]]]);
    AuditLog::factory()->create(['group_id' => $child->group_id, 'subject_type' => 'App\\Models\\EmergencyResponse', 'subject_id' => $response->id, 'data' => ['volunteer' => true]]);
    Schema::table('emergency_responses', fn (Blueprint $table) => $table->boolean('volunteer')->default(false));
    DB::table('emergency_responses')->update(['volunteer' => true]);
    Schema::create('lottery_draws', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('emergency_allocation_id')->unique()->constrained();
        $table->json('winners');
    });
    DB::table('lottery_draws')->insert(['emergency_allocation_id' => $allocation->id, 'winners' => json_encode([$child->id])]);

    $migration = require database_path('migrations/2026_11_06_191744_remove_voluntary_allocation.php');
    $migration->up();

    expect(Schema::hasTable('lottery_draws'))->toBeFalse();
    expect(Schema::hasColumn('emergency_responses', 'volunteer'))->toBeFalse();
    foreach (['emergency_allocations', 'emergency_responses', 'emergency_days', 'allocation_results', 'waiting_list_movements', 'personal_access_tokens', 'email_deliveries'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('audit_logs', ['event' => 'waiting_list.initialized']);
    $this->assertModelExists($child);
    $this->assertModelExists($admin);
    $this->assertModelExists($contact);
    $this->assertModelExists($year);
    $this->assertDatabaseHas('waiting_list_entries', ['child_id' => $child->id, 'position' => 1]);
    $this->assertDatabaseCount('regular_care_assignments', 1);
});

it('ignores obsolete participation input and displays only regular and waiting allocation', function () {
    [$year, $children, $allocation] = careScenario(['never']);
    $response = $allocation->responses()->sole();
    $contact = ParentContact::factory()->for($children->first()->family)->create();
    $token = bin2hex(random_bytes(32));
    PersonalAccessToken::factory()->for($response, 'response')->for($contact)->create(['token_hash' => hash('sha256', $token)]);
    $this->get(route('parent.show', $token))->assertOk()->assertDontSee('name="volunteer"', false)->assertDontSee('Losverfahren');

    $this->post(route('parent.update', $token), ['waiting_needed' => false, 'volunteer' => true])->assertRedirect();

    $service = app(EmergencyAllocationService::class);
    $service->close($allocation);
    $proposal = $service->propose($allocation);
    expect($proposal['remaining'])->toBe(1);
    expect($proposal['rows'][0]['awarded'])->toBeFalse();
    expect($proposal)->not->toHaveKey('lottery');
    expect($proposal['rows'][0]['snapshot']['response'])->not->toHaveKey('volunteer');
    $this->actingAs(User::factory()->administrator()->create());
    $this->get(route('allocations.show', $allocation))->assertOk()->assertDontSee('name="volunteer"', false)->assertDontSee('Phase 3')->assertDontSee('Losergebnis');
    $this->get(route('dashboard'))->assertOk()->assertSee('Reguläre Ansprüche')->assertSee('Warteliste')->assertDontSee('Freiwillige');
});

it('skips queued mail for a removed procedure', function () {
    Notification::fake();

    (new SendCareEmail(1))->handle();

    Notification::assertNothingSent();
});
