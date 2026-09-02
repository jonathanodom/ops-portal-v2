<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_notification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 120);
            $table->string('category', 60);
            $table->string('title', 180);
            $table->text('body');
            $table->string('action_url', 2048)->nullable();
            $table->string('related_type', 120)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 20)->default('normal');
            $table->json('metadata')->nullable();
            $table->json('audience');
            $table->json('default_channels');
            $table->json('required_channels');
            $table->char('payload_sha256', 64);
            $table->char('deduplication_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['organization_id', 'deduplication_hash'], 'portal_notification_dedup_unique');
            $table->index(['organization_id', 'event_key', 'occurred_at'], 'portal_notification_event_timeline_idx');
            $table->index(['organization_id', 'related_type', 'related_id'], 'portal_notification_related_idx');
        });

        Schema::create('portal_notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('portal_notification_event_id');
            $table->foreign('portal_notification_event_id', 'portal_notification_event_fk')
                ->references('id')
                ->on('portal_notification_events')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('channels');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['portal_notification_event_id', 'user_id'], 'portal_notification_event_user_unique');
            $table->index(['organization_id', 'user_id', 'read_at'], 'portal_notification_in_app_idx');
        });

        Schema::create('portal_notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 120)->default('*');
            $table->boolean('in_app_enabled')->nullable();
            $table->boolean('email_enabled')->nullable();
            $table->boolean('push_enabled')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'event_key'], 'portal_notification_preference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_notification_preferences');
        Schema::dropIfExists('portal_notification_recipients');
        Schema::dropIfExists('portal_notification_events');
    }
};
