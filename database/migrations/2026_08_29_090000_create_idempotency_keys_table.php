<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic idempotency-key ledger for /api/v1 create endpoints, per
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §5/§14.
 * Not ticket-specific: any future write endpoint reuses this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('route', 191);
            $table->string('idempotency_key', 191);
            $table->unsignedSmallInteger('response_status')->default(0);
            $table->json('response_data')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'route', 'idempotency_key'], 'idempotency_keys_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
