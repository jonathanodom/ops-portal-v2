<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_ticket_reopens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->text('reason');
            $table->text('prior_status_reason')->nullable();
            $table->timestamp('prior_status_changed_at')->nullable();
            $table->foreignId('prior_status_changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reopened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at');
            $table->index(['organization_id', 'service_ticket_id', 'reopened_at'], 'ticket_reopens_timeline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_reopens');
    }
};
