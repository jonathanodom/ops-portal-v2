<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_publications', function (Blueprint $table): void {
            $table->timestamp('first_viewed_at')->nullable()->after('published_at');
            $table->timestamp('changes_requested_at')->nullable()->after('first_viewed_at');
            $table->timestamp('accepted_at')->nullable()->after('changes_requested_at');
            $table->timestamp('superseded_at')->nullable()->after('accepted_at');
            $table->timestamp('extended_at')->nullable()->after('superseded_at');
            $table->foreignId('extended_by_id')->nullable()->after('extended_at')->constrained('users')->nullOnDelete();
            $table->json('extension_review_snapshot')->nullable()->after('extended_by_id');
        });

        Schema::table('proposal_recipients', function (Blueprint $table): void {
            $table->timestamp('first_viewed_at')->nullable()->after('created_by_id');
            $table->timestamp('last_viewed_at')->nullable()->after('first_viewed_at');
        });

        Schema::table('proposal_share_links', function (Blueprint $table): void {
            $table->timestamp('first_viewed_at')->nullable()->after('created_by_id');
            $table->timestamp('last_viewed_at')->nullable()->after('first_viewed_at');
        });

        Schema::create('proposal_engagement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposal_share_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('target_type', 30)->nullable();
            $table->string('target_reference', 100)->nullable();
            $table->text('encrypted_ip')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('safe_metadata')->nullable();
            $table->timestamp('owner_notified_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['proposal_publication_id', 'event_type', 'occurred_at'], 'proposal_engagement_timeline_idx');
            $table->index(['organization_id', 'event_type', 'occurred_at'], 'proposal_engagement_org_idx');
        });

        Schema::create('proposal_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposal_share_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('proposal_comments')->restrictOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_type', 20);
            $table->text('author_name')->nullable();
            $table->text('author_email')->nullable();
            $table->string('target_type', 20)->default('proposal');
            $table->string('target_reference', 100)->nullable();
            $table->text('body');
            $table->timestamps();
            $table->index(['proposal_publication_id', 'created_at'], 'proposal_comment_timeline_idx');
        });

        Schema::create('proposal_option_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_recipient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_share_link_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('publication_line_id');
            $table->boolean('included');
            $table->timestamps();
            $table->unique(['proposal_publication_id', 'proposal_recipient_id', 'publication_line_id'], 'proposal_recipient_option_unique');
            $table->unique(['proposal_publication_id', 'proposal_share_link_id', 'publication_line_id'], 'proposal_share_option_unique');
        });

        Schema::create('proposal_email_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_recipient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_share_link_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('email');
            $table->string('email_hash', 64);
            $table->string('challenge_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['proposal_publication_id', 'email_hash', 'status'], 'proposal_email_verification_idx');
        });

        Schema::create('proposal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposal_share_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('publication_hash', 64);
            $table->string('revision_content_hash', 64);
            $table->json('accepted_snapshot');
            $table->string('accepted_snapshot_hash', 64);
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('discount_cents');
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('total_cents');
            $table->text('signer_name');
            $table->text('signer_email');
            $table->text('signer_title');
            $table->text('consent_statement');
            $table->string('consent_version', 40);
            $table->string('signature_disk', 50);
            $table->string('signature_key');
            $table->string('signature_mime_type', 100)->default('image/png');
            $table->unsignedBigInteger('signature_byte_size');
            $table->unsignedInteger('signature_width');
            $table->unsignedInteger('signature_height');
            $table->string('signature_sha256', 64);
            $table->timestamp('signed_at');
            $table->text('encrypted_ip')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->uuid('idempotency_token');
            $table->timestamps();
            $table->unique('proposal_publication_id');
            $table->unique(['organization_id', 'idempotency_token']);
        });

        Schema::create('proposal_acceptance_line_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_acceptance_id');
            $table->foreign('proposal_acceptance_id', 'proposal_acceptance_line_acceptance_fk')
                ->references('id')->on('proposal_acceptances')->restrictOnDelete();
            $table->unsignedBigInteger('publication_line_id');
            $table->boolean('optional');
            $table->boolean('included');
            $table->json('line_snapshot');
            $table->timestamps();
            $table->unique(['proposal_acceptance_id', 'publication_line_id'], 'proposal_acceptance_line_unique');
        });

        Schema::create('accepted_payment_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_acceptance_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_milestone_id')->nullable();
            $table->string('name', 120);
            $table->string('amount_type', 20);
            $table->unsignedBigInteger('amount_value');
            $table->unsignedBigInteger('allocated_cents');
            $table->boolean('is_balancing')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['proposal_acceptance_id', 'sort_order'], 'accepted_milestone_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accepted_payment_milestones');
        Schema::dropIfExists('proposal_acceptance_line_selections');
        Schema::dropIfExists('proposal_acceptances');
        Schema::dropIfExists('proposal_email_verifications');
        Schema::dropIfExists('proposal_option_selections');
        Schema::dropIfExists('proposal_comments');
        Schema::dropIfExists('proposal_engagement_events');

        Schema::table('proposal_share_links', fn (Blueprint $table) => $table->dropColumn(['first_viewed_at', 'last_viewed_at']));
        Schema::table('proposal_recipients', fn (Blueprint $table) => $table->dropColumn(['first_viewed_at', 'last_viewed_at']));
        Schema::table('proposal_publications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('extended_by_id');
            $table->dropColumn(['first_viewed_at', 'changes_requested_at', 'accepted_at', 'superseded_at', 'extended_at', 'extension_review_snapshot']);
        });
    }
};
