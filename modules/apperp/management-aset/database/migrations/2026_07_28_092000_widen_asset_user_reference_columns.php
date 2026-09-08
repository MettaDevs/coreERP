<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cabang untuk mesin database selain PostgreSQL dibuang pada F3-16.
 *
 * Migration ini dulu memulangkan diri lebih awal pada SQLite, karena test module berjalan di
 * SQLite dalam memori sementara produksi memakai PostgreSQL. Akibatnya suite membuktikan
 * perilaku pada mesin yang tidak pernah dipakai siapa pun — dan pada mesin yang benar,
 * migration ini tidak pernah diuji sama sekali.
 *
 * Sekarang hanya ada satu mesin. Cabangnya dibuang, bukan disimpan "untuk jaga-jaga":
 * cabang yang tidak pernah dijalankan adalah kode yang tidak pernah dibuktikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN received_by_user_id TYPE varchar(64)');
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN custodian_user_id TYPE varchar(64)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN received_by_user_id TYPE char(26)');
        DB::statement('ALTER TABLE t_aset_penempatan ALTER COLUMN custodian_user_id TYPE char(26)');
    }
};
