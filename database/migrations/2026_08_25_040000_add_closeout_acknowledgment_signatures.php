<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closeouts', function (Blueprint $table): void {
            $table->string('representative_role', 120)->nullable()->after('representative_name');
        });

        Schema::create('closeout_acknowledgment_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closeout_id')->unique()->constrained()->restrictOnDelete();
            $table->string('signer_name');
            $table->string('signer_role', 120)->nullable();
            $table->string('statement_version', 30);
            $table->text('statement_snapshot');
            $table->string('storage_disk', 50);
            $table->string('storage_key');
            $table->string('mime_type', 100)->default('image/png');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('signed_at');
            $table->foreignId('captured_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'signed_at'], 'cas_org_signed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closeout_acknowledgment_signatures');
        Schema::table('closeouts', fn (Blueprint $table) => $table->dropColumn('representative_role'));
    }
};
