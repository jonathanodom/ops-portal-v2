<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->foreignId('return_follow_up_source_ticket_id')->nullable()->after('billing_disposition')
                ->constrained('service_tickets')->restrictOnDelete();
            $table->foreignId('return_follow_up_source_closeout_id')->nullable()->after('return_follow_up_source_ticket_id')
                ->constrained('closeouts')->restrictOnDelete();
            $table->string('return_follow_up_original_purpose', 30)->nullable()->after('return_follow_up_source_closeout_id');
            $table->string('return_follow_up_status', 30)->nullable()->after('return_follow_up_original_purpose');

            $table->unique('return_follow_up_source_closeout_id', 'tickets_return_closeout_unique');
            $table->index(
                ['organization_id', 'return_follow_up_status'],
                'tickets_return_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table): void {
            $table->dropIndex('tickets_return_status_index');
            $table->dropUnique('tickets_return_closeout_unique');
            $table->dropConstrainedForeignId('return_follow_up_source_closeout_id');
            $table->dropConstrainedForeignId('return_follow_up_source_ticket_id');
            $table->dropColumn(['return_follow_up_original_purpose', 'return_follow_up_status']);
        });
    }
};
