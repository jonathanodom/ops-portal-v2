<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40);
            $table->string('state', 20)->default('stored');
            $table->string('storage_disk', 50);
            $table->string('storage_key')->unique();
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('byte_size');
            $table->string('caption', 500)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'project_id', 'state'], 'project_attachments_org_project_state_index');
            $table->index(['project_id', 'category'], 'project_attachments_project_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_attachments');
    }
};
