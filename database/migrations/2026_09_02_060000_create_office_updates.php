<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->string('audience_type', 30);
            $table->json('audience_snapshot');
            $table->unsignedInteger('recipient_count');
            $table->foreignId('published_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->char('publish_token_hash', 64);
            $table->char('request_sha256', 64);
            $table->timestamps();

            $table->unique(['organization_id', 'publish_token_hash'], 'office_updates_publish_token_unique');
            $table->index(['organization_id', 'published_at'], 'office_updates_history_idx');
        });

        Schema::create('office_update_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['office_update_id', 'user_id'], 'office_update_user_unique');
            $table->index(['organization_id', 'user_id', 'office_update_id'], 'office_update_recipient_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_update_recipients');
        Schema::dropIfExists('office_updates');
    }
};
