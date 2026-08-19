<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_transactions', 'payment_source')) {
            Schema::table('payment_transactions', function (Blueprint $table): void {
                $table->string('payment_source', 24)->nullable()->after('method');
                $table->index(['organization_id', 'payment_source'], 'payment_transaction_source_index');
            });
        }

        DB::table('payment_transactions')
            ->whereNull('provider')
            ->update(['payment_source' => 'manual']);

        DB::table('payment_transactions')
            ->whereNotNull('provider')
            ->update(['payment_source' => 'hosted_checkout']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_transactions', 'payment_source')) {
            Schema::table('payment_transactions', function (Blueprint $table): void {
                $table->dropIndex('payment_transaction_source_index');
                $table->dropColumn('payment_source');
            });
        }
    }
};
