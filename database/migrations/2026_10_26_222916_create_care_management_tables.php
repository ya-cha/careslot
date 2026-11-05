<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false);
        });
        Schema::create('kita_years', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('waiting_list_initialized_at')->nullable();
            $table->timestamps();
        });
        Schema::create('families', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('parent_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_id')->constrained();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
            $table->unique(['family_id', 'email']);
        });
        Schema::create('children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kita_year_id')->constrained();
            $table->foreignId('family_id')->constrained();
            $table->string('first_name');
            $table->string('last_name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('regular_care_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('child_id')->constrained();
            $table->unsignedTinyInteger('weekday');
            $table->string('pattern');
            $table->string('time_mode')->default('all_day');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->index(['child_id', 'weekday', 'valid_from']);
        });
        Schema::create('waiting_list_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kita_year_id')->constrained();
            $table->foreignId('child_id')->constrained();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['kita_year_id', 'position']);
            $table->unique(['kita_year_id', 'child_id']);
        });
        Schema::create('emergency_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kita_year_id')->constrained();
            $table->date('date');
            $table->unsignedInteger('capacity');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });
        Schema::create('emergency_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_day_id')->constrained();
            $table->foreignId('previous_allocation_id')->nullable()->constrained('emergency_allocations');
            $table->unsignedInteger('capacity');
            $table->string('status')->default('responses');
            $table->text('reason')->nullable();
            $table->timestamp('response_deadline')->nullable();
            $table->timestamp('responses_closed_at')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->unsignedBigInteger('source_revision')->nullable();
            $table->json('proposal')->nullable();
            $table->timestamps();
        });
        Schema::create('emergency_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_allocation_id')->constrained();
            $table->foreignId('child_id')->constrained();
            $table->json('claim_snapshot');
            $table->boolean('regular_needed')->nullable();
            $table->boolean('waiting_needed')->default(false);
            $table->boolean('volunteer')->default(false);
            $table->timestamp('responded_at')->nullable();
            $table->json('time_resolution')->nullable();
            $table->timestamps();
            $table->unique(['emergency_allocation_id', 'child_id']);
        });
        Schema::create('allocation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_allocation_id')->constrained();
            $table->foreignId('child_id')->constrained();
            $table->unsignedTinyInteger('phase')->nullable();
            $table->boolean('awarded');
            $table->boolean('offered');
            $table->boolean('takes_place');
            $table->unsignedInteger('original_position')->nullable();
            $table->unsignedInteger('new_position')->nullable();
            $table->text('reason');
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['emergency_allocation_id', 'child_id']);
        });
        Schema::create('waiting_list_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_allocation_id')->constrained();
            $table->foreignId('child_id')->constrained();
            $table->unsignedInteger('original_position');
            $table->unsignedInteger('new_position');
            $table->text('reason');
            $table->timestamps();
            $table->unique(['emergency_allocation_id', 'child_id']);
        });
        Schema::create('lottery_draws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_allocation_id')->unique()->constrained();
            $table->unsignedInteger('available_places');
            $table->json('candidates');
            $table->json('winners');
            $table->json('losers');
            $table->timestamp('drawn_at');
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_response_id')->constrained();
            $table->foreignId('parent_contact_id')->constrained();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('event');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('data');
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });
        Schema::create('email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emergency_allocation_id')->constrained();
            $table->foreignId('parent_contact_id')->constrained();
            $table->string('deduplication_key')->unique();
            $table->string('kind');
            $table->text('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['email_deliveries', 'audit_logs', 'personal_access_tokens', 'lottery_draws', 'waiting_list_movements', 'allocation_results', 'emergency_responses', 'emergency_allocations', 'emergency_days', 'waiting_list_entries', 'regular_care_assignments', 'children', 'parent_contacts', 'families', 'kita_years'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
