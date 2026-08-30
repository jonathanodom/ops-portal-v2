<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_lead_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('received');
            $table->string('source', 30);

            $table->string('first_name', 80);
            $table->string('last_name', 120);
            $table->string('phone', 40);
            $table->string('email', 254);
            $table->string('customer_type', 60);
            $table->string('zip', 20);
            $table->string('company')->nullable();
            $table->string('service_interest');
            $table->string('selected_plan')->nullable();
            $table->string('preferred_contact', 40);
            $table->string('timeline', 100)->nullable();
            $table->text('details');

            $table->string('originating_page', 2048)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('referrer', 2048)->nullable();

            $table->timestamp('contact_consent_at')->nullable();
            $table->string('contact_consent_ip', 45)->nullable();
            $table->string('contact_consent_version', 100)->nullable();
            $table->timestamp('sms_consent_at')->nullable();
            $table->string('sms_consent_ip', 45)->nullable();
            $table->string('sms_consent_version', 100)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->timestamp('received_at');
            $table->text('error_message')->nullable();

            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'lead_intake_org_status_idx');
            $table->index('email');
            $table->index('phone');
            $table->index('payload_sha256');
            $table->index('opportunity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_lead_intakes');
    }
};
