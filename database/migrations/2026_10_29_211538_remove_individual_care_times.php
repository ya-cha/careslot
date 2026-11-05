<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $responses = DB::table('emergency_responses')->join('emergency_allocations', 'emergency_allocations.id', '=', 'emergency_responses.emergency_allocation_id')->join('emergency_days', 'emergency_days.id', '=', 'emergency_allocations.emergency_day_id')->where('emergency_allocations.status', '!=', 'confirmed')->select('emergency_responses.*', 'emergency_days.date')->get();
        foreach ($responses as $response) {
            $snapshot = json_decode($response->claim_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $rules = $snapshot['rules'] ?? [];
            foreach ($rules as &$rule) {
                unset($rule['time_mode'], $rule['starts_at'], $rule['ends_at']);
            }
            unset($rule);
            $pattern = count($rules) === 1 ? $rules[0]['pattern'] : null;
            $week = (int) (new DateTimeImmutable($response->date))->format('W');
            $eligible = match ($pattern) {
                'always' => true, 'never' => false, 'even' => $week % 2 === 0, 'odd' => $week % 2 === 1, default => null
            };
            $snapshot = ['status' => $eligible === null ? 'ambiguous' : ($eligible ? 'full' : 'none'), 'rules' => $rules, 'explanation' => $eligible === null ? 'Anspruchsregeln fehlen oder sind widersprüchlich.' : ($eligible ? 'Regulärer Anspruch.' : 'Kein regulärer Anspruch.')];
            DB::table('emergency_responses')->where('id', $response->id)->update(['claim_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
        }
        foreach (DB::table('emergency_allocations')->where('status', 'proposed')->get() as $allocation) {
            $groupId = DB::table('emergency_days')->where('id', $allocation->emergency_day_id)->value('group_id');
            DB::table('audit_logs')->insert(['group_id' => $groupId, 'event' => 'allocation.proposal_discarded_after_time_removal', 'subject_type' => 'App\\Models\\EmergencyAllocation', 'subject_id' => $allocation->id, 'data' => json_encode(['proposal' => json_decode($allocation->proposal, true)]), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lottery_draws')->where('emergency_allocation_id', $allocation->id)->delete();
            DB::table('emergency_allocations')->where('id', $allocation->id)->update(['status' => 'closed', 'proposal' => null, 'source_revision' => null, 'calculated_at' => null]);
        }
        Schema::table('regular_care_assignments', fn (Blueprint $table) => $table->dropColumn(['time_mode', 'starts_at', 'ends_at']));
        Schema::table('emergency_responses', fn (Blueprint $table) => $table->dropColumn('time_resolution'));
    }

    public function down(): void
    {
        throw new LogicException('Entfernte individuelle Betreuungszeiten können nicht wiederhergestellt werden.');
    }
};
