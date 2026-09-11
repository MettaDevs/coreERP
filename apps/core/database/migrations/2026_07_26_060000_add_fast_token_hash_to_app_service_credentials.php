<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service tokens were verified with bcrypt, measured at 251ms per call, on every internal API request. bcrypt is
 * deliberately slow to protect low-entropy human passwords; a service token is high-entropy random, so the slowness
 * bought nothing and capped throughput at roughly four requests per second per worker.
 *
 * New tokens are `<credential_id>.<secret>`: the id makes the lookup O(1) and the secret is compared as a SHA-256
 * digest with hash_equals, which is constant-time and sub-millisecond. `secret_hash` stays for credentials issued
 * before this change so nothing breaks on upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_service_credentials', function (Blueprint $table): void {
            $table->string('token_digest', 64)->nullable()->index();
            // Credentials issued from now on carry no bcrypt hash at all.
            $table->string('secret_hash')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('app_service_credentials', function (Blueprint $table): void {
            $table->dropColumn('token_digest');
            $table->string('secret_hash')->nullable(false)->change();
        });
    }
};
