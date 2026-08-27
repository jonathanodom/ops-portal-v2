<?php

use App\Domain\Commercial\CommercialDefaults;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_commercial_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('default_proposal_expiration_days')->default(30);
            $table->unsignedSmallInteger('gross_margin_floor_bps')->default(2000);
            $table->unsignedSmallInteger('discount_approval_ceiling_bps')->default(1500);
            $table->boolean('approve_manual_price_overrides')->default(true);
            $table->boolean('approve_below_cost_lines')->default(true);
            $table->boolean('approve_terms_overrides')->default(true);
            $table->unsignedSmallInteger('first_reminder_days')->default(7);
            $table->unsignedSmallInteger('second_reminder_days')->default(2);
            $table->boolean('customer_show_line_details')->default(true);
            $table->boolean('customer_show_optional_items')->default(true);
            $table->string('signature_statement_version', 40)->default('v1');
            $table->string('notification_policy', 40)->default('staff_only');
            $table->timestamps();
        });

        Schema::create('opportunity_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('semantic_kind', 30);
            $table->unsignedSmallInteger('default_probability_bps')->default(0);
            $table->string('color', 20)->default('slate');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'semantic_kind']);
            $table->index(['organization_id', 'active', 'sort_order']);
        });

        Schema::create('opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('opportunity_number');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stage_id')->constrained('opportunity_stages')->restrictOnDelete();
            $table->string('title');
            $table->string('priority', 20)->default('normal');
            $table->unsignedBigInteger('estimated_value_cents')->default(0);
            $table->date('estimated_close_on')->nullable();
            $table->unsignedSmallInteger('probability_override_bps')->nullable();
            $table->string('lead_source', 100)->nullable();
            $table->string('referral_source', 150)->nullable();
            $table->string('classification', 100)->nullable();
            $table->string('next_action', 500)->nullable();
            $table->string('lost_reason', 100)->nullable();
            $table->text('lost_note')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'opportunity_number']);
            $table->index(['organization_id', 'stage_id', 'updated_at']);
            $table->index(['organization_id', 'owner_user_id', 'estimated_close_on']);
            $table->index(['organization_id', 'customer_id']);
        });

        Schema::create('opportunity_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'assigned_to_user_id', 'status', 'due_on']);
            $table->index(['organization_id', 'opportunity_id', 'status']);
        });

        Schema::create('opportunity_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40);
            $table->text('body')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['organization_id', 'opportunity_id', 'occurred_at']);
        });

        Schema::create('opportunity_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 20)->default('stored');
            $table->string('storage_disk', 50);
            $table->string('storage_key')->unique();
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('byte_size');
            $table->string('caption', 500)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'opportunity_id', 'state']);
        });

        Schema::create('commercial_user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('opportunity_view', 20)->default('kanban');
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Organization::query()->eachById(
            fn (Organization $organization) => app(CommercialDefaults::class)->ensure($organization),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_user_preferences');
        Schema::dropIfExists('opportunity_attachments');
        Schema::dropIfExists('opportunity_activities');
        Schema::dropIfExists('opportunity_tasks');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('opportunity_stages');
        Schema::dropIfExists('organization_commercial_settings');
    }
};
