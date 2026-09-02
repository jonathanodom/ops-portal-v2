<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_lead_intakes', function (Blueprint $table): void {
            $table->string('engagement_status', 40)->nullable()->after('status');
            $table->timestamp('next_follow_up_at')->nullable()->after('engagement_status');
            $table->foreignId('engagement_changed_by_id')->nullable()->after('next_follow_up_at')->constrained('users')->nullOnDelete();
            $table->timestamp('engagement_changed_at')->nullable()->after('engagement_changed_by_id');
            $table->index(['organization_id', 'engagement_status'], 'lead_intake_org_engagement_idx');
            $table->index(['organization_id', 'next_follow_up_at'], 'lead_intake_org_follow_up_idx');
        });

        Schema::create('commercial_lead_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_lead_intake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['organization_id', 'commercial_lead_intake_id', 'occurred_at'], 'lead_activity_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_lead_activities');

        Schema::table('commercial_lead_intakes', function (Blueprint $table): void {
            $table->dropIndex('lead_intake_org_engagement_idx');
            $table->dropIndex('lead_intake_org_follow_up_idx');
            $table->dropConstrainedForeignId('engagement_changed_by_id');
            $table->dropColumn(['engagement_status', 'next_follow_up_at', 'engagement_changed_at']);
        });
    }
};
