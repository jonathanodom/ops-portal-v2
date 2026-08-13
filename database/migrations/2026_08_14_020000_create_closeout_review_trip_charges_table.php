<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closeout_review_trip_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('closeout_review_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('catalog_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_service_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('recorded_travel_seconds');
            $table->string('catalog_code_snapshot');
            $table->string('catalog_name_snapshot');
            $table->text('catalog_description_snapshot')->nullable();
            $table->string('catalog_unit_code_snapshot', 40);
            $table->string('catalog_unit_name_snapshot', 80);
            $table->unsignedBigInteger('catalog_unit_price_cents');
            $table->boolean('catalog_taxable');
            $table->foreignId('selected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('selected_at');
            $table->timestamps();
            $table->index(['organization_id', 'visit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closeout_review_trip_charges');
    }
};
