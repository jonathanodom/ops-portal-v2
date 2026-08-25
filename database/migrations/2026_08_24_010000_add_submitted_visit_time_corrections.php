<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_time_entries', function (Blueprint $table): void {
            $table->timestamp('corrected_started_at')->nullable()->after('ended_at');
            $table->timestamp('corrected_ended_at')->nullable()->after('corrected_started_at');
        });

        Schema::create('visit_time_entry_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_time_entry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamp('previous_started_at');
            $table->timestamp('previous_ended_at');
            $table->timestamp('corrected_started_at');
            $table->timestamp('corrected_ended_at');
            $table->text('reason');
            $table->foreignId('corrected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['visit_time_entry_id', 'sequence'], 'visit_time_correction_sequence_unique');
            $table->index(['organization_id', 'visit_time_entry_id'], 'visit_time_correction_org_entry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_time_entry_corrections');

        Schema::table('visit_time_entries', function (Blueprint $table): void {
            $table->dropColumn(['corrected_started_at', 'corrected_ended_at']);
        });
    }
};
