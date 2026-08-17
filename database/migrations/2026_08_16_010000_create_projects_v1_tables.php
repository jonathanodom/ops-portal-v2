<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('project_number');
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('service_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->string('name');
            $table->string('type', 40);
            $table->string('status', 30)->default('planning');
            $table->text('summary')->nullable();
            $table->text('objective')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_on')->nullable();
            $table->date('target_end_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'project_number']);
            $table->index(['organization_id', 'status', 'updated_at']);
            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'owner_user_id']);
        });

        Schema::create('project_workstreams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('planned');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workstream_id')->nullable()->constrained('project_workstreams')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('backlog');
            $table->string('priority', 20)->default('normal');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['organization_id', 'assigned_to_user_id', 'due_on']);
            $table->index(['organization_id', 'due_on', 'status']);
        });

        Schema::create('project_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('planned');
            $table->date('target_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['organization_id', 'target_on', 'status']);
        });

        Schema::create('project_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30)->default('note');
            $table->text('body');
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'created_at']);
        });

        Schema::create('project_service_ticket', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('linked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at');
            $table->timestamps();
            $table->unique(['project_id', 'service_ticket_id']);
            $table->index(['organization_id', 'service_ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_service_ticket');
        Schema::dropIfExists('project_notes');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_workstreams');
        Schema::dropIfExists('projects');
    }
};
