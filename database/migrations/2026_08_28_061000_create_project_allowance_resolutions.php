<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_allowance_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_commercial_scope_id');
            $table->foreign('project_commercial_scope_id', 'project_allowance_scope_fk')
                ->references('id')->on('project_commercial_scopes')->restrictOnDelete();
            $table->unsignedBigInteger('source_revision_line_id');
            $table->string('description');
            $table->unsignedBigInteger('accepted_amount_cents');
            $table->unsignedBigInteger('resolved_amount_cents');
            $table->bigInteger('variance_cents');
            $table->string('status', 30);
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at');
            $table->timestamps();
            $table->unique(['project_commercial_scope_id', 'source_revision_line_id'], 'project_allowance_scope_line_unique');
            $table->index(['organization_id', 'project_id', 'status'], 'project_allowance_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_allowance_resolutions');
    }
};
