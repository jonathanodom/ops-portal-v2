<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->unsignedInteger('schedule_version')->default(0)->after('scheduled_end_at');
        });

        Schema::create('visit_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('schedule_version');
            $table->string('method', 20);
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->text('note')->nullable();
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at');
            $table->timestamp('created_at');

            $table->index(['organization_id', 'visit_id', 'schedule_version'], 'visit_confirmations_current_index');
            $table->index(['organization_id', 'confirmed_at'], 'visit_confirmations_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_confirmations');

        Schema::table('visits', function (Blueprint $table): void {
            $table->dropColumn('schedule_version');
        });
    }
};
