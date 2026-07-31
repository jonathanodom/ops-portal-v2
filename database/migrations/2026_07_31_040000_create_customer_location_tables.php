<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->string('display_name');
            $table->string('legal_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('status', 40)->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'display_name']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->boolean('is_preferred')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'active']);
        });

        Schema::create('service_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name');
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city', 100);
            $table->string('state', 2);
            $table->string('postal_code', 10)->index();
            $table->string('timezone', 80);
            $table->text('access_instructions')->nullable();
            $table->text('site_notes')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'active']);
            $table->index(['organization_id', 'city', 'state']);
        });

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 100)->index();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('service_locations');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('customers');
    }
};
