<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('user_id');
            $table->text('endpoint');
            $table->char('endpoint_sha256', 64);
            $table->string('public_key', 512);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 32)->default('aes128gcm');
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_registered_at');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'browser_push_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id', 'browser_push_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['organization_id', 'endpoint_sha256'], 'browser_push_org_endpoint_uq');
            $table->index(['organization_id', 'user_id', 'disabled_at'], 'browser_push_recipient_idx');
        });

        Schema::create('portal_notification_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('portal_notification_recipient_id');
            $table->foreignId('browser_push_subscription_id');
            $table->string('status', 24)->default('queued');
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamps();

            $table->foreign('organization_id', 'notification_push_org_fk')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('portal_notification_recipient_id', 'notification_push_recipient_fk')->references('id')->on('portal_notification_recipients')->cascadeOnDelete();
            $table->foreign('browser_push_subscription_id', 'notification_push_subscription_fk')->references('id')->on('browser_push_subscriptions')->cascadeOnDelete();
            $table->unique(
                ['portal_notification_recipient_id', 'browser_push_subscription_id'],
                'notification_push_recipient_subscription_uq',
            );
            $table->index(['organization_id', 'status'], 'notification_push_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_notification_push_deliveries');
        Schema::dropIfExists('browser_push_subscriptions');
    }
};
