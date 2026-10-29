<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('identity_hash', 64)->unique();
            $table->text('issuer');
            $table->string('subject', 255);
            $table->string('name', 100);
            $table->string('email', 250)->nullable();
            $table->string('verified_email', 250)->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX product_customers_verified_email_active ON product_customers (verified_email) WHERE deleted_at IS NULL AND verified_email IS NOT NULL');
        Schema::create('product_customer_sessions', function (Blueprint $table): void {
            $table->string('token_hash', 64)->primary();
            $table->foreignUuid('customer_id')->constrained('product_customers');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['customer_id', 'expires_at']);
        });
        Schema::create('product_auth_attempts', function (Blueprint $table): void {
            $table->string('state_hash', 64)->primary();
            $table->uuid('id')->unique();
            $table->string('browser_challenge', 64);
            $table->string('nonce', 128);
            $table->text('code_verifier');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        Schema::create('product_email_challenges', function (Blueprint $table): void {
            $table->string('token_hash', 64)->primary();
            $table->foreignUuid('customer_id')->constrained('product_customers');
            $table->string('email', 250);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['customer_id', 'created_at']);
        });
        Schema::table('managed_product_tenants', function (Blueprint $table): void {
            $table->foreignUuid('owner_customer_id')->nullable()->constrained('product_customers');
            $table->jsonb('pending_sso_revocation')->nullable();
        });
        DB::statement('ALTER TABLE managed_product_tenants ALTER COLUMN requested_by DROP NOT NULL');
        DB::statement('CREATE UNIQUE INDEX managed_product_tenants_customer_product_active ON managed_product_tenants (owner_customer_id, product) WHERE deleted_at IS NULL AND owner_customer_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS managed_product_tenants_customer_product_active');
        Schema::table('managed_product_tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_customer_id');
            $table->dropColumn('pending_sso_revocation');
        });
        Schema::dropIfExists('product_email_challenges');
        Schema::dropIfExists('product_auth_attempts');
        Schema::dropIfExists('product_customer_sessions');
        Schema::dropIfExists('product_customers');
    }
};
