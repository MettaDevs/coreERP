<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_identities', function (Blueprint $table): void {
            $table->softDeletes();
            $table->dropUnique(['issuer', 'subject']);
            $table->dropUnique(['user_id', 'issuer']);
        });
        DB::statement('CREATE UNIQUE INDEX external_identities_issuer_subject_unique ON external_identities (issuer, subject) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX external_identities_user_id_issuer_unique ON external_identities (user_id, issuer) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        // Riwayat tautan dipertahankan; rollback ke indeks lama dapat melanggar keunikannya.
    }
};
