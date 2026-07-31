<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closeouts', function (Blueprint $table): void {
            $table->foreignId('parent_closeout_id')->nullable()->after('visit_id')->constrained('closeouts')->nullOnDelete();
            $table->index(['organization_id', 'visit_id', 'version']);
        });

        Schema::table('visit_part_proposals', function (Blueprint $table): void {
            $table->foreignId('source_proposal_id')->nullable()->after('closeout_id')->constrained('visit_part_proposals')->nullOnDelete();
        });

        Schema::create('closeout_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closeout_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            $table->string('disposition', 30)->nullable();
            $table->text('disposition_reason')->nullable();
            $table->boolean('self_review_override')->default(false);
            $table->uuid('decision_token')->unique();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['organization_id', 'decision', 'decided_at']);
        });

        Schema::create('closeout_review_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closeout_review_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('visit_time_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('visit_part_proposal_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('excluded')->default(false);
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->decimal('approved_quantity', 10, 2)->nullable();
            $table->string('approved_unit', 40)->nullable();
            $table->string('approved_billing_treatment', 30)->nullable();
            $table->text('reason');
            $table->timestamps();
            $table->index(['organization_id', 'type']);
            $table->unique(['closeout_review_id', 'visit_time_entry_id'], 'cra_review_time_unique');
            $table->unique(['closeout_review_id', 'visit_part_proposal_id'], 'cra_review_part_unique');
        });

        Schema::create('billing_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('closeout_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('ready');
            $table->unsignedInteger('approved_time_minutes')->default(0);
            $table->unsignedInteger('approved_parts_count')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handed_off_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_off_at')->nullable();
            $table->uuid('acknowledgment_token')->nullable()->unique();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_handoffs');
        Schema::dropIfExists('closeout_review_adjustments');
        Schema::dropIfExists('closeout_reviews');
        Schema::table('visit_part_proposals', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_proposal_id'));
        Schema::table('closeouts', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_closeout_id'));
    }
};
