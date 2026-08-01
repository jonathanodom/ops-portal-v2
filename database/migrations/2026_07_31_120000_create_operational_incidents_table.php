<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 60);
            $table->string('severity', 20);
            $table->char('fingerprint', 64)->unique();
            $table->string('subject_type', 120)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id')->nullable();
            $table->json('context')->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_occurred_at');
            $table->timestamp('last_occurred_at');
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'severity'], 'incidents_health_queue');
            $table->index(['category', 'last_occurred_at'], 'incidents_category_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_incidents');
    }
};
