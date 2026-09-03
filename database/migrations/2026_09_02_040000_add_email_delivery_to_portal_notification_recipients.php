<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_notification_recipients', function (Blueprint $table): void {
            $table->timestamp('email_queued_at')->nullable()->after('read_at');
            $table->timestamp('email_sent_at')->nullable()->after('email_queued_at');
            $table->timestamp('email_failed_at')->nullable()->after('email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('portal_notification_recipients', function (Blueprint $table): void {
            $table->dropColumn(['email_queued_at', 'email_sent_at', 'email_failed_at']);
        });
    }
};
