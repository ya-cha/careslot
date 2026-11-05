<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('care_groups')->where('name', 'Standardgruppe')->pluck('id') as $groupId) {
                foreach (['children', 'families', 'emergency_days', 'waiting_list_entries', 'group_years', 'audit_logs', 'users'] as $table) {
                    if (DB::table($table)->where('group_id', $groupId)->exists()) {
                        throw new LogicException('Die automatisch angelegte Gruppe hat noch zugeordnete Daten. Diese zuerst ausdrücklich bereinigen oder einer angelegten Gruppe zuordnen.');
                    }
                }
                DB::table('care_groups')->where('id', $groupId)->delete();
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Die entfernte automatische Gruppe wird nicht erneut angelegt.');
    }
};
