<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_billing_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('seller_name')->nullable();
            $table->string('seller_legal_name')->nullable();
            $table->string('seller_email')->nullable();
            $table->string('seller_phone', 50)->nullable();
            $table->string('seller_address_line_1')->nullable();
            $table->string('seller_address_line_2')->nullable();
            $table->string('seller_city', 100)->nullable();
            $table->string('seller_state', 2)->nullable();
            $table->string('seller_postal_code', 20)->nullable();
            $table->string('default_currency', 3)->default('USD');
            $table->string('default_payment_terms', 30)->default('due_on_receipt');
            $table->unsignedInteger('default_tax_rate_basis_points')->default(0);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('billing_labor_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedBigInteger('hourly_rate_cents');
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'active', 'is_default']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_handoff_id')->constrained()->restrictOnDelete();
            $table->foreignId('reissue_of_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedInteger('generation')->default(1);
            $table->string('invoice_number', 50);
            $table->string('status', 30)->default('draft');
            $table->string('currency', 3)->default('USD');
            $table->string('payment_terms', 30)->default('due_on_receipt');
            $table->date('due_on')->nullable();
            $table->string('billing_name');
            $table->string('billing_legal_name')->nullable();
            $table->string('billing_contact_name')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('billing_phone', 50)->nullable();
            $table->string('billing_address_line_1')->nullable();
            $table->string('billing_address_line_2')->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 2)->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_legal_name')->nullable();
            $table->string('seller_email')->nullable();
            $table->string('seller_phone', 50)->nullable();
            $table->string('seller_address_line_1')->nullable();
            $table->string('seller_address_line_2')->nullable();
            $table->string('seller_city', 100)->nullable();
            $table->string('seller_state', 2)->nullable();
            $table->string('seller_postal_code', 20)->nullable();
            $table->string('discount_type', 20)->nullable();
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedInteger('tax_rate_basis_points')->default(0);
            $table->text('tax_override_reason')->nullable();
            $table->text('discount_reason')->nullable();
            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('discount_total_cents')->default(0);
            $table->unsignedBigInteger('tax_total_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->uuid('creation_token')->unique();
            $table->uuid('issue_token')->nullable()->unique();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->uuid('void_token')->nullable()->unique();
            $table->string('pdf_status', 20)->default('not_requested');
            $table->string('pdf_disk', 50)->nullable();
            $table->string('pdf_key')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->text('pdf_failure_code')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'invoice_number']);
            $table->unique(['billing_handoff_id', 'generation']);
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['organization_id', 'service_ticket_id']);
        });

        Schema::table('billing_handoffs', function (Blueprint $table): void {
            $table->foreignId('current_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });

        Schema::create('invoice_closeouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('closeout_id')->constrained()->restrictOnDelete();
            $table->foreignId('closeout_review_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['invoice_id', 'closeout_id']);
            $table->index(['organization_id', 'visit_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('line_type', 30);
            $table->text('description');
            $table->unsignedBigInteger('quantity_millis')->default(1000);
            $table->string('unit', 40)->nullable();
            $table->unsignedBigInteger('unit_price_cents')->nullable();
            $table->boolean('included')->default(true);
            $table->string('billing_treatment', 30)->nullable();
            $table->boolean('taxable')->default(false);
            $table->unsignedInteger('tax_rate_basis_points')->default(0);
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->foreignId('labor_rate_id')->nullable()->constrained('billing_labor_rates')->nullOnDelete();
            $table->foreignId('source_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('source_closeout_id')->nullable()->constrained('closeouts')->nullOnDelete();
            $table->foreignId('source_review_id')->nullable()->constrained('closeout_reviews')->nullOnDelete();
            $table->foreignId('source_time_entry_id')->nullable()->constrained('visit_time_entries')->nullOnDelete();
            $table->foreignId('source_part_proposal_id')->nullable()->constrained('visit_part_proposals')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'included', 'sort_order']);
            $table->index(['invoice_id', 'source_visit_id', 'line_type'], 'invoice_visit_type_index');
            $table->unique(['invoice_id', 'source_part_proposal_id'], 'invoice_part_source_unique');
        });

        Schema::create('invoice_acknowledgments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('contact_name');
            $table->boolean('confirmed');
            $table->foreignId('presented_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at');
            $table->uuid('acknowledgment_token')->unique();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id', 'acknowledged_at'], 'invoice_ack_org_invoice_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_acknowledgments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoice_closeouts');
        Schema::table('billing_handoffs', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_invoice_id'));
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_labor_rates');
        Schema::dropIfExists('organization_billing_settings');
    }
};
