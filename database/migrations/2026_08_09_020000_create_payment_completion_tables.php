<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('provider', 20);
            $table->string('environment', 20);
            $table->text('api_secret')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('location_id')->nullable();
            $table->string('credential_fingerprint', 16)->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('connection_status', 20)->default('untested');
            $table->string('external_account_id')->nullable();
            $table->string('last_test_code', 80)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->foreignId('last_tested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'provider']);
            $table->index(['organization_id', 'enabled', 'connection_status'], 'payment_provider_readiness_index');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('preferred_payment_provider', 20)->nullable()->after('payment_terms');
            $table->string('electronic_payment_provider', 20)->nullable()->after('preferred_payment_provider');
            $table->timestamp('payment_provider_locked_at')->nullable()->after('electronic_payment_provider');
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_provider_configuration_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 24)->default('open');
            $table->uuid('idempotency_key')->unique();
            $table->string('return_token_hash', 64)->unique();
            $table->text('hosted_url')->nullable();
            $table->string('provider_session_id')->nullable();
            $table->string('provider_order_id')->nullable();
            $table->string('provider_payment_id')->nullable();
            $table->string('safe_failure_code', 80)->nullable();
            $table->foreignId('initiated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id', 'status'], 'payment_attempt_invoice_status_index');
            $table->unique(['provider', 'provider_session_id']);
        });

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('original_transaction_id')->nullable()->constrained('payment_transactions')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->string('provider', 20)->nullable();
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('provider_transaction_id')->nullable();
            $table->string('safe_processor_reference', 80)->nullable();
            $table->string('manual_reference', 120)->nullable();
            $table->text('reason')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('received_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id', 'status'], 'payment_transaction_invoice_status_index');
            $table->unique(['provider', 'provider_transaction_id']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_provider_configuration_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_event_id');
            $table->string('event_type', 100);
            $table->string('payload_sha256', 64);
            $table->string('status', 20)->default('received');
            $table->string('safe_failure_code', 80)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_provider_configuration_id', 'provider_event_id'], 'payment_webhook_event_unique');
        });

        Schema::create('payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->timestamp('token_rotated_at')->nullable();
            $table->foreignId('token_rotated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pdf_status', 20)->default('pending');
            $table->string('pdf_disk', 50)->nullable();
            $table->string('pdf_key')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->string('pdf_failure_code', 80)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_attempts');
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['preferred_payment_provider', 'electronic_payment_provider', 'payment_provider_locked_at']);
        });
        Schema::dropIfExists('payment_provider_configurations');
    }
};
