<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closeout_reviews', function (Blueprint $table): void {
            $table->boolean('administrative_completion')->default(false)->after('self_review_override');
            $table->text('administrative_completion_reason')->nullable()->after('administrative_completion');
            $table->timestamp('administratively_completed_at')->nullable()->after('administrative_completion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('closeout_reviews', function (Blueprint $table): void {
            $table->dropColumn(['administrative_completion', 'administrative_completion_reason', 'administratively_completed_at']);
        });
    }
};
