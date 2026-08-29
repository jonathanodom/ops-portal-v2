<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accepted_payment_milestones', function (Blueprint $table): void {
            $table->foreignId('invoice_id')->nullable()->after('source_milestone_id')->constrained()->restrictOnDelete();
            $table->json('allocation_snapshot')->nullable()->after('allocated_cents');
            $table->unique('invoice_id', 'accepted_milestone_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accepted_payment_milestones', function (Blueprint $table): void {
            $table->dropUnique('accepted_milestone_invoice_unique');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn('allocation_snapshot');
        });
    }
};
