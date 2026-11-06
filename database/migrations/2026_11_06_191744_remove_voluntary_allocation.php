<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lottery_draws');

        foreach (['email_deliveries', 'personal_access_tokens', 'waiting_list_movements', 'allocation_results', 'emergency_responses'] as $table) {
            DB::table($table)->delete();
        }
        DB::table('emergency_allocations')->update(['previous_allocation_id' => null]);
        DB::table('emergency_allocations')->delete();
        DB::table('emergency_days')->delete();
        DB::table('audit_logs')->whereIn('subject_type', ['App\\Models\\EmergencyAllocation', 'App\\Models\\EmergencyResponse', 'App\\Models\\EmergencyDay', 'App\\Models\\PersonalAccessToken', 'App\\Models\\EmailDelivery', 'App\\Models\\LotteryDraw'])->delete();

        Schema::table('emergency_responses', fn (Blueprint $table) => $table->dropColumn('volunteer'));
    }

    public function down(): void
    {
        throw new LogicException('Entfernte Vergabedaten und freiwillige Teilnahme können nicht wiederhergestellt werden.');
    }
};
