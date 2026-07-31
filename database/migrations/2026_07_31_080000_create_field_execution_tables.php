<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closeouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('content_version')->default(1);
            $table->string('outcome', 40)->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('work_performed')->nullable();
            $table->text('exceptions')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('unfinished_work')->nullable();
            $table->text('needed_equipment')->nullable();
            $table->text('hold_reason')->nullable();
            $table->string('unavailable_category', 50)->nullable();
            $table->text('unavailable_detail')->nullable();
            $table->string('representative_name')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('ack_unavailable_category', 50)->nullable();
            $table->text('ack_unavailable_detail')->nullable();
            $table->string('no_photo_category', 50)->nullable();
            $table->text('no_photo_detail')->nullable();
            $table->uuid('submitted_token')->nullable()->unique();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('return_visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('last_saved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['visit_id', 'version']);
            $table->index(['organization_id', 'status']);
        });
        Schema::table('visits', fn (Blueprint $table) => $table->foreignId('current_closeout_id')->nullable()->after('return_of_visit_id')->constrained('closeouts')->nullOnDelete());
        Schema::create('visit_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closeout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('category', 20);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('source', 30)->default('timer');
            $table->text('note')->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'visit_id', 'started_at']);
        });
        Schema::create('visit_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closeout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('storage_disk', 50);
            $table->string('storage_key');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->string('category', 30);
            $table->string('caption')->nullable();
            $table->string('state', 20)->default('stored');
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'closeout_id', 'state']);
        });
        Schema::create('visit_part_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closeout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->string('unit', 40)->nullable();
            $table->string('serial_mac')->nullable();
            $table->string('billing_treatment', 30);
            $table->text('technician_note')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'closeout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_part_proposals');
        Schema::dropIfExists('visit_media');
        Schema::dropIfExists('visit_time_entries');
        Schema::table('visits', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_closeout_id'));
        Schema::dropIfExists('closeouts');
    }
};
