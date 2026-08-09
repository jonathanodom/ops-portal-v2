<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->softDeletes()->after('updated_at');
            $table->foreignId('archived_by_id')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable()->after('archived_by_id');
            $table->foreignId('restored_by_id')->nullable()->after('archive_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable()->after('restored_by_id');
            $table->index(['organization_id', 'deleted_at', 'status'], 'visits_archive_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropIndex('visits_archive_lookup');
            $table->dropConstrainedForeignId('restored_by_id');
            $table->dropColumn('restored_at');
            $table->dropColumn('archive_reason');
            $table->dropConstrainedForeignId('archived_by_id');
            $table->dropSoftDeletes();
        });
    }
};
