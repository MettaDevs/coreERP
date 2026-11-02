<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_customers', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->constrained('users');
        });

        Schema::create('tenant_creation_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('actor_scope', 100);
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->foreignId('principal_id')->constrained('users');
            $table->foreignUlid('tenant_id')->constrained('tenants');
            $table->foreignUlid('membership_id')->constrained('tenant_memberships');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['actor_scope', 'idempotency_key'], 'tenant_creation_actor_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_creation_requests');
        Schema::table('product_customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
