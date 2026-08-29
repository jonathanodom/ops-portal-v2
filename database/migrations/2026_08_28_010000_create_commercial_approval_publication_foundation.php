<?php

use App\Domain\Commercial\CommercialDefaults;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_commercial_settings', function (Blueprint $table): void {
            $table->boolean('customer_show_location_totals')->default(true);
            $table->string('customer_labor_grouping', 20)->default('location');
            $table->boolean('customer_show_manufacturer_model')->default(false);
            $table->boolean('customer_show_product_images')->default(false);
            $table->boolean('customer_show_package_components')->default(false);
        });

        Schema::create('commercial_content_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('heading');
            $table->text('body')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'active', 'name'], 'commercial_content_org_active_idx');
        });

        Schema::create('commercial_terms_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->unsignedSmallInteger('version');
            $table->longText('body');
            $table->boolean('approved')->default(true);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'code', 'version'], 'commercial_terms_org_code_version_unique');
            $table->index(['organization_id', 'active', 'name'], 'commercial_terms_org_active_idx');
        });

        Schema::create('proposal_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('template_type', 40);
            $table->string('name');
            $table->boolean('acceptance_enabled')->default(true);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'template_type'], 'proposal_template_org_type_unique');
        });

        Schema::create('proposal_template_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_template_id')->constrained()->cascadeOnDelete();
            $table->string('section_type', 40);
            $table->string('heading');
            $table->boolean('customer_visible')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['proposal_template_id', 'sort_order'], 'proposal_template_section_sort_idx');
        });

        Schema::table('commercial_revisions', function (Blueprint $table): void {
            $table->foreignId('commercial_terms_set_id')->nullable()->after('currency')->constrained()->restrictOnDelete();
            $table->string('terms_name_snapshot')->nullable()->after('commercial_terms_set_id');
            $table->unsignedSmallInteger('terms_version_snapshot')->nullable()->after('terms_name_snapshot');
            $table->longText('terms_body_snapshot')->nullable()->after('terms_version_snapshot');
            $table->boolean('terms_overridden')->default(false)->after('terms_body_snapshot');
        });

        Schema::table('commercial_revision_sections', function (Blueprint $table): void {
            $table->foreignId('source_content_block_id')->nullable()->after('commercial_revision_id')->constrained('commercial_content_blocks')->nullOnDelete();
        });

        Schema::create('commercial_revision_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 20);
            $table->string('storage_disk', 50)->nullable();
            $table->string('storage_key')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('embed_url', 2048)->nullable();
            $table->string('caption', 500)->nullable();
            $table->string('state', 20)->default('stored');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['commercial_revision_id', 'state', 'id'], 'commercial_revision_media_state_idx');
        });

        Schema::create('commercial_revision_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->cascadeOnDelete();
            $table->string('content_hash', 64);
            $table->string('status', 20);
            $table->json('trigger_snapshot');
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['commercial_revision_id', 'content_hash'], 'commercial_revision_hash_approval_unique');
            $table->index(['organization_id', 'status', 'requested_at'], 'commercial_approval_queue_idx');
        });

        Schema::create('proposal_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_template_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->string('revision_content_hash', 64);
            $table->string('publication_hash', 64);
            $table->json('snapshot');
            $table->unsignedBigInteger('subtotal_cents');
            $table->unsignedBigInteger('discount_cents');
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('total_cents');
            $table->boolean('acceptance_enabled')->default(true);
            $table->boolean('show_line_details')->default(true);
            $table->boolean('show_location_totals')->default(true);
            $table->string('labor_grouping', 20)->default('location');
            $table->boolean('show_manufacturer_model')->default(false);
            $table->boolean('show_product_images')->default(false);
            $table->boolean('show_package_components')->default(false);
            $table->foreignId('brand_asset_id')->nullable()->constrained('organization_brand_assets')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->string('pdf_status', 20)->default('pending');
            $table->string('pdf_disk', 50)->nullable();
            $table->string('pdf_key')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->string('pdf_failure_code', 100)->nullable();
            $table->foreignId('published_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->foreignId('withdrawn_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique('commercial_revision_id');
            $table->unique(['organization_id', 'publication_hash']);
            $table->index(['organization_id', 'status', 'expires_at'], 'proposal_publication_status_expiry_idx');
        });

        Schema::create('proposal_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email');
            $table->string('token_hash', 64);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('token_hash');
            $table->index(['proposal_publication_id', 'revoked_at'], 'proposal_recipient_active_idx');
        });

        Schema::create('proposal_share_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('token_hash', 64);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('token_hash');
            $table->index(['proposal_publication_id', 'revoked_at'], 'proposal_share_active_idx');
        });

        Schema::create('proposal_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('proposal_publication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delivery_type', 20);
            $table->string('status', 20)->default('queued');
            $table->string('idempotency_key', 100);
            $table->string('safe_failure_code', 100)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['proposal_publication_id', 'idempotency_key'], 'proposal_delivery_idempotency_unique');
            $table->index(['organization_id', 'status', 'created_at'], 'proposal_delivery_status_idx');
        });

        Organization::query()->eachById(fn (Organization $organization) => app(CommercialDefaults::class)->ensure($organization));
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_delivery_attempts');
        Schema::dropIfExists('proposal_share_links');
        Schema::dropIfExists('proposal_recipients');
        Schema::dropIfExists('proposal_publications');
        Schema::dropIfExists('commercial_revision_approvals');
        Schema::dropIfExists('commercial_revision_media');
        Schema::table('commercial_revision_sections', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_content_block_id'));
        Schema::table('commercial_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('commercial_terms_set_id');
            $table->dropColumn(['terms_name_snapshot', 'terms_version_snapshot', 'terms_body_snapshot', 'terms_overridden']);
        });
        Schema::dropIfExists('proposal_template_sections');
        Schema::dropIfExists('proposal_templates');
        Schema::dropIfExists('commercial_terms_sets');
        Schema::dropIfExists('commercial_content_blocks');
        Schema::table('organization_commercial_settings', fn (Blueprint $table) => $table->dropColumn(['customer_show_location_totals', 'customer_labor_grouping', 'customer_show_manufacturer_model', 'customer_show_product_images', 'customer_show_package_components']));
    }
};
