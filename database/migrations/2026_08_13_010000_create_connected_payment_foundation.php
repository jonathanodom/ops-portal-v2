<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            $table->string('connection_method', 30)->default('legacy_credentials')->after('environment');
            $table->string('external_account_name')->nullable()->after('external_account_id');
            $table->text('oauth_access_token')->nullable()->after('external_account_name');
            $table->text('oauth_refresh_token')->nullable()->after('oauth_access_token');
            $table->timestamp('oauth_expires_at')->nullable()->after('oauth_refresh_token');
            $table->timestamp('connected_at')->nullable()->after('oauth_expires_at');
            $table->foreignId('connected_by_id')->nullable()->after('connected_at')->constrained('users')->nullOnDelete();
            $table->timestamp('last_refreshed_at')->nullable()->after('connected_by_id');
            $table->timestamp('disconnected_at')->nullable()->after('last_refreshed_at');
        });

        Schema::table('organization_billing_settings', function (Blueprint $table): void {
            $table->string('default_payment_provider', 20)->nullable()->after('default_payment_terms');
        });

        Schema::create('payment_provider_authorization_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('state_hash', 64)->unique();
            $table->text('pkce_verifier')->nullable();
            $table->string('environment', 20);
            $table->string('return_path', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'provider', 'expires_at'], 'payment_authorization_state_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_authorization_states');

        Schema::table('organization_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('default_payment_provider');
        });

        Schema::table('payment_provider_configurations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('connected_by_id');
            $table->dropColumn([
                'connection_method', 'external_account_name', 'oauth_access_token', 'oauth_refresh_token',
                'oauth_expires_at', 'connected_at', 'last_refreshed_at', 'disconnected_at',
            ]);
        });
    }
};
