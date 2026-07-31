<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('capability_role', function (Blueprint $table) {
            $table->foreignId('capability_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['capability_id', 'role_id']);
        });

        Schema::create('organization_membership_capability', function (Blueprint $table) {
            $table->foreignId('organization_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capability_id')->constrained()->cascadeOnDelete();
            $table->string('effect', 10);
            $table->primary(
                ['organization_membership_id', 'capability_id'],
                'membership_capability_primary',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_membership_capability');
        Schema::dropIfExists('capability_role');
        Schema::dropIfExists('capabilities');
    }
};
