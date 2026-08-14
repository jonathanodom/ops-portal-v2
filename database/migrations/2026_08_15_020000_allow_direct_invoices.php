<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('service_ticket_id')->nullable()->change();
            $table->foreignId('billing_handoff_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('service_ticket_id')->nullable(false)->change();
            $table->foreignId('billing_handoff_id')->nullable(false)->change();
        });
    }
};
