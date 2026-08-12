<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_service_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('catalog_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_service_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->unsignedBigInteger('billing_amount_cents');
            $table->text('billing_amount_override_reason')->nullable();
            $table->string('billing_cadence', 20);
            $table->unsignedSmallInteger('billing_interval');
            $table->boolean('taxable_snapshot')->default(false);
            $table->string('service_code_snapshot', 80);
            $table->string('service_name_snapshot', 160);
            $table->text('service_description_snapshot')->nullable();
            $table->string('service_unit_code_snapshot', 80);
            $table->string('service_unit_name_snapshot', 100);
            $table->string('variant_code_snapshot', 80)->nullable();
            $table->string('variant_label_snapshot', 120)->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('current_scope_key', 64)->nullable()->unique();
            $table->timestamp('status_changed_at');
            $table->foreignId('status_changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('canceled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'start_date'], 'customer_service_org_status_start_index');
            $table->index(['organization_id', 'customer_id', 'status'], 'customer_service_customer_status_index');
            $table->index(['organization_id', 'catalog_service_id', 'status'], 'customer_service_catalog_status_index');
            $table->index(['organization_id', 'next_billing_date'], 'customer_service_next_billing_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service_enrollments');
    }
};
