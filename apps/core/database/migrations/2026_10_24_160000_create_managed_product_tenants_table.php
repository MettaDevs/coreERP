<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_product_tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('product', 60);
            $table->string('profile', 60);
            $table->string('name', 150);
            $table->string('slug', 63);
            $table->string('owner_name', 100);
            $table->string('owner_email', 250);
            $table->uuid('idempotency_key')->unique();
            $table->string('request_hash', 64);
            $table->jsonb('release');
            $table->string('status', 20)->default('queued');
            $table->text('public_url')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->boolean('retry_requested')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'lease_until']);
        });
        DB::statement('CREATE UNIQUE INDEX managed_product_tenants_product_slug_active ON managed_product_tenants (product, slug) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE managed_product_tenants ADD CONSTRAINT managed_product_tenants_status CHECK (status IN ('queued', 'provisioning', 'ready', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_product_tenants');
    }
};
