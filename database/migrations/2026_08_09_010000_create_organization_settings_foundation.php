<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('email')->nullable()->after('timezone');
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('website')->nullable()->after('phone');
            $table->string('address_line_1')->nullable()->after('website');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city', 100)->nullable()->after('address_line_2');
            $table->string('state', 2)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state');
            $table->string('country_code', 2)->default('US')->after('postal_code');
        });

        Schema::create('organization_brand_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('variant', 20);
            $table->string('storage_disk', 50);
            $table->string('storage_key');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'variant', 'created_at'], 'org_brand_variant_created_index');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreignId('full_logo_asset_id')->nullable()->after('country_code')->constrained('organization_brand_assets')->nullOnDelete();
            $table->foreignId('mark_logo_asset_id')->nullable()->after('full_logo_asset_id')->constrained('organization_brand_assets')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('seller_logo_asset_id')->nullable()->after('seller_postal_code')->constrained('organization_brand_assets')->restrictOnDelete();
        });

        DB::table('organizations')->orderBy('id')->each(function (object $organization): void {
            $billing = DB::table('organization_billing_settings')->where('organization_id', $organization->id)->first();
            if (! $billing) {
                return;
            }
            DB::table('organizations')->where('id', $organization->id)->update([
                'legal_name' => $billing->seller_legal_name,
                'email' => $billing->seller_email,
                'phone' => $billing->seller_phone,
                'address_line_1' => $billing->seller_address_line_1,
                'address_line_2' => $billing->seller_address_line_2,
                'city' => $billing->seller_city,
                'state' => $billing->seller_state,
                'postal_code' => $billing->seller_postal_code,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('seller_logo_asset_id'));
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mark_logo_asset_id');
            $table->dropConstrainedForeignId('full_logo_asset_id');
        });
        Schema::dropIfExists('organization_brand_assets');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['legal_name', 'email', 'phone', 'website', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country_code']);
        });
    }
};
