<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        $groupId = DB::table('care_groups')->insertGetId(['name' => 'Standardgruppe', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('group_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('care_groups');
            $table->foreignId('kita_year_id')->constrained();
            $table->timestamp('waiting_list_initialized_at')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['group_id', 'kita_year_id']);
        });
        foreach (['children', 'families', 'emergency_days', 'waiting_list_entries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('group_id')->nullable()->constrained('care_groups');
            });
            DB::table($name)->update(['group_id' => $groupId]);
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('group_id')->nullable(false)->change());
        }
        foreach (['users', 'audit_logs'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('group_id')->nullable()->constrained('care_groups'));
        }
        DB::table('audit_logs')->update(['group_id' => $groupId]);
        foreach (DB::table('kita_years')->get() as $year) {
            DB::table('group_years')->insert(['group_id' => $groupId, 'kita_year_id' => $year->id, 'waiting_list_initialized_at' => $year->waiting_list_initialized_at, 'revision' => $year->revision, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('waiting_list_entries', function (Blueprint $table): void {
            $table->dropUnique(['kita_year_id', 'position']);
            $table->unique(['group_id', 'kita_year_id', 'position'], 'waiting_list_group_year_position_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Gruppenzuordnungen können nicht verlustfrei automatisch zurückgerollt werden.');
    }
};
