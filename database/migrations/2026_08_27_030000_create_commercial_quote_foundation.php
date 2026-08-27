<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->boolean('tax_exempt')->default(false)->after('status');
            $table->string('tax_exemption_reference', 120)->nullable()->after('tax_exempt');
        });

        foreach (['commercial_systems', 'commercial_phases'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->unique(['organization_id', 'name']);
                $table->index(['organization_id', 'active', 'sort_order'], $name === 'commercial_systems' ? 'commercial_system_default_idx' : 'commercial_phase_default_idx');
            });
        }

        Schema::create('commercial_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 20)->default('quote');
            $table->string('document_number', 40);
            $table->foreignId('opportunity_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'document_number']);
            $table->index(['organization_id', 'opportunity_id', 'updated_at'], 'commercial_doc_opportunity_idx');
        });

        Schema::create('commercial_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->foreignId('source_revision_id')->nullable()->constrained('commercial_revisions')->restrictOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('currency', 3)->default('USD');
            $table->string('discount_type', 20)->nullable();
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedSmallInteger('tax_rate_basis_points')->default(0);
            $table->boolean('tax_rate_overridden')->default(false);
            $table->text('tax_override_reason')->nullable();
            $table->boolean('customer_tax_exempt')->default(false);
            $table->string('tax_exemption_reference', 120)->nullable();
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('line_discount_total_cents')->default(0);
            $table->unsignedBigInteger('quote_discount_total_cents')->default(0);
            $table->unsignedBigInteger('tax_total_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('resolved_cost_cents')->default(0);
            $table->boolean('cost_complete')->default(true);
            $table->bigInteger('gross_profit_cents')->nullable();
            $table->integer('gross_margin_basis_points')->nullable();
            $table->integer('markup_basis_points')->nullable();
            $table->unsignedInteger('content_version')->default(1);
            $table->string('content_hash', 64);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['commercial_document_id', 'version']);
            $table->index(['organization_id', 'status', 'updated_at'], 'commercial_revision_status_idx');
        });

        Schema::create('commercial_revision_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('commercial_revision_locations')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['commercial_revision_id', 'parent_id', 'sort_order'], 'commercial_location_tree_idx');
        });

        foreach (['commercial_revision_systems', 'commercial_revision_phases'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->foreignId('organization_id')->constrained()->restrictOnDelete();
                $table->foreignId('commercial_revision_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('source_default_id')->nullable();
                $table->string('name', 100);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['commercial_revision_id', 'sort_order'], $name === 'commercial_revision_systems' ? 'commercial_revision_system_idx' : 'commercial_revision_phase_idx');
            });
        }

        Schema::create('commercial_revision_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->cascadeOnDelete();
            $table->string('heading');
            $table->text('body')->nullable();
            $table->boolean('customer_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('commercial_revision_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('commercial_revision_locations')->nullOnDelete();
            $table->foreignId('system_id')->nullable()->constrained('commercial_revision_systems')->nullOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('commercial_revision_phases')->nullOnDelete();
            $table->foreignId('catalog_category_id')->nullable()->constrained('catalog_categories')->nullOnDelete();
            $table->string('line_type', 20);
            $table->foreignId('catalog_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_service_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_code', 100)->nullable();
            $table->string('description');
            $table->text('customer_description')->nullable();
            $table->string('unit_code', 40);
            $table->string('unit_name', 80);
            $table->unsignedBigInteger('quantity_millis')->default(1000);
            $table->unsignedBigInteger('catalog_unit_sell_cents')->nullable();
            $table->unsignedBigInteger('effective_unit_sell_cents');
            $table->boolean('sell_price_overridden')->default(false);
            $table->unsignedBigInteger('cost_basis_cents')->nullable();
            $table->unsignedBigInteger('cost_basis_quantity_millis')->nullable();
            $table->boolean('cost_resolved')->default(false);
            $table->string('discount_type', 20)->nullable();
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->boolean('optional')->default(false);
            $table->boolean('included')->default(true);
            $table->boolean('taxable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('gross_sell_cents')->default(0);
            $table->unsignedBigInteger('line_discount_cents')->default(0);
            $table->unsignedBigInteger('quote_discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedBigInteger('resolved_cost_cents')->nullable();
            $table->timestamps();
            $table->index(['commercial_revision_id', 'sort_order'], 'commercial_revision_line_sort_idx');
            $table->index(['organization_id', 'line_type'], 'commercial_line_type_idx');
        });

        Schema::create('commercial_revision_line_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_line_id')->constrained()->cascadeOnDelete();
            $table->string('component_type', 20);
            $table->foreignId('catalog_product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_code', 100)->nullable();
            $table->string('name');
            $table->string('unit_code', 40);
            $table->string('unit_name', 80);
            $table->unsignedBigInteger('quantity_millis');
            $table->unsignedSmallInteger('waste_basis_points')->default(0);
            $table->unsignedBigInteger('unit_sell_cents')->nullable();
            $table->unsignedBigInteger('cost_basis_cents')->nullable();
            $table->unsignedBigInteger('cost_basis_quantity_millis')->nullable();
            $table->boolean('cost_resolved')->default(false);
            $table->boolean('customer_visible')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['commercial_revision_line_id', 'sort_order'], 'commercial_line_component_sort_idx');
        });

        Schema::create('commercial_payment_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_revision_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('amount_type', 20);
            $table->unsignedBigInteger('amount_value');
            $table->unsignedBigInteger('allocated_cents')->default(0);
            $table->boolean('is_balancing')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['commercial_revision_id', 'sort_order'], 'commercial_payment_schedule_idx');
        });

        Organization::query()->eachById(function (Organization $organization): void {
            foreach (['Network', 'Surveillance', 'Audio', 'Video', 'Access Control', 'Security'] as $index => $name) {
                DB::table('commercial_systems')->insertOrIgnore(['organization_id' => $organization->id, 'name' => $name, 'sort_order' => ($index + 1) * 10, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (['Design', 'Rough-In', 'Trim', 'Final', 'Programming', 'Commissioning'] as $index => $name) {
                DB::table('commercial_phases')->insertOrIgnore(['organization_id' => $organization->id, 'name' => $name, 'sort_order' => ($index + 1) * 10, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_payment_milestones');
        Schema::dropIfExists('commercial_revision_line_components');
        Schema::dropIfExists('commercial_revision_lines');
        Schema::dropIfExists('commercial_revision_sections');
        Schema::dropIfExists('commercial_revision_phases');
        Schema::dropIfExists('commercial_revision_systems');
        Schema::dropIfExists('commercial_revision_locations');
        Schema::dropIfExists('commercial_revisions');
        Schema::dropIfExists('commercial_documents');
        Schema::dropIfExists('commercial_phases');
        Schema::dropIfExists('commercial_systems');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['tax_exempt', 'tax_exemption_reference']));
    }
};
