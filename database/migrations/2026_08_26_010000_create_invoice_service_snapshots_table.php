<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_service_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('snapshot_json');
            $table->string('snapshot_sha256', 64);
            $table->timestamp('captured_at');
            $table->foreignId('captured_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'service_ticket_id'], 'invoice_service_snapshots_org_ticket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_service_snapshots');
    }
};
