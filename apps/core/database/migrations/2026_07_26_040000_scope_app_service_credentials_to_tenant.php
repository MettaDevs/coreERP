<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service credential authenticated an app but not a tenant, so one leaked token could issue numbers for every
 * tenant that had installed that app.
 *
 * `tenant_id` is nullable on purpose: null keeps the existing shared-credential behaviour so nothing breaks on
 * upgrade, while a credential with a tenant is only accepted for that tenant. New credentials should be scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_service_credentials', function (Blueprint $table): void {
            $table->foreignUlid('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->index(['app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('app_service_credentials', function (Blueprint $table): void {
            $table->dropIndex(['app_id', 'status']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
