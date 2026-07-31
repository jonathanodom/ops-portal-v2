<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'document_type', 'year'], 'document_sequences_scope_unique');
        });

        Schema::create('service_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('ticket_number', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('customer_visible_summary')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('source', 20)->default('internal');
            $table->string('status', 20)->default('open');
            $table->text('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'ticket_number']);
            $table->index(['organization_id', 'status', 'priority']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'service_location_id']);
        });

        Schema::create('service_ticket_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['organization_id', 'service_ticket_id', 'created_at'], 'ticket_notes_timeline');
        });

        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('return_of_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('status', 30)->default('planned');
            $table->string('timezone', 80);
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('en_route_at')->nullable();
            $table->foreignId('en_route_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('on_site_at')->nullable();
            $table->foreignId('on_site_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('canceled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->foreignId('scheduled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'scheduled_start_at'], 'visits_dispatch_queue');
            $table->index(['organization_id', 'service_ticket_id']);
            $table->index(['organization_id', 'service_location_id']);
        });

        Schema::create('visit_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_membership_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->foreignId('assigned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['visit_id', 'organization_membership_id']);
            $table->index(['organization_id', 'organization_membership_id', 'visit_id'], 'visit_assignments_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_assignments');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('service_ticket_notes');
        Schema::dropIfExists('service_tickets');
        Schema::dropIfExists('document_sequences');
    }
};
