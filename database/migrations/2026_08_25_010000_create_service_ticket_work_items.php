<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_ticket_work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discovered_visit_id')->nullable()->constrained('visits')->restrictOnDelete();
            $table->string('origin', 30);
            $table->string('title');
            $table->text('detail')->nullable();
            $table->text('work_note')->nullable();
            $table->string('status', 30)->default('open');
            $table->foreignId('follow_up_service_ticket_id')->nullable()->constrained('service_tickets')->restrictOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'service_ticket_id', 'status'], 'ticket_work_items_status');
            $table->index(['organization_id', 'status', 'updated_at'], 'ticket_work_items_attention');
            $table->unique('follow_up_service_ticket_id', 'ticket_work_items_follow_up_unique');
        });

        Schema::create('service_ticket_work_item_visit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained(indexName: 'stwi_visit_org_fk')->cascadeOnDelete();
            $table->foreignId('service_ticket_work_item_id')->constrained(indexName: 'stwi_visit_item_fk')->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained(indexName: 'stwi_visit_visit_fk')->restrictOnDelete();
            $table->foreignId('first_touched_by_id')->nullable()->constrained('users', indexName: 'stwi_visit_first_actor_fk')->nullOnDelete();
            $table->timestamp('first_touched_at');
            $table->foreignId('last_touched_by_id')->nullable()->constrained('users', indexName: 'stwi_visit_last_actor_fk')->nullOnDelete();
            $table->timestamp('last_touched_at');
            $table->timestamps();

            $table->unique(['service_ticket_work_item_id', 'visit_id'], 'ticket_work_item_visit_unique');
            $table->index(['organization_id', 'visit_id'], 'ticket_work_item_visit_context');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_work_item_visit');
        Schema::dropIfExists('service_ticket_work_items');
    }
};
