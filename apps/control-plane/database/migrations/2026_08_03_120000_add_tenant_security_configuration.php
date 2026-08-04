<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['security_privileges', 'security_duties'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->nullable()->after('app_id')->constrained()->cascadeOnDelete();
                $table->string('source', 20)->default('manifest')->after('name');
                $table->string('status', 20)->default('active')->after('source');
                $table->timestamp('published_at')->nullable()->after('status');
                $table->index(['tenant_id', 'source', 'status']);
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('app_id', 80)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['security_privileges', 'security_duties'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex([$tableName === 'security_privileges' ? 'tenant_id' : 'tenant_id', 'source', 'status']);
                $table->dropConstrainedForeignId('tenant_id');
                $table->dropColumn(['source', 'status', 'published_at']);
            });
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('app_id', 80)->nullable(false)->change();
            });
        }
    }
};
