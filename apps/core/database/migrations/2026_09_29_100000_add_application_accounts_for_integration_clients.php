<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akun aplikasi untuk klien integrasi, padanan `Microsoft Entra Application."User ID"` di Business
 * Central: setiap aplikasi yang tersambung punya baris User sendiri, dan perubahan yang ia buat tercatat
 * atas namanya. Tanpa itu, penulisan lewat `internal/v1` tercatat sebagai sistem (keputusan pemilik
 * 29 September 2026, opsi A, README analisa gap BC Gap 1 dan 6).
 *
 * `users.account_type` membedakan orang dari akun aplikasi. Akun aplikasi tidak pernah dapat masuk:
 * penyedia pengguna di kedua app hanya membaca `person`. `integration_clients.user_id` menautkan klien ke
 * akunnya, tanpa foreign key, karena `users` tinggal di database pusat sedangkan klien di database tenant.
 *
 * Tanpa kelas `App\`: admin.erp ikut menjalankan migration Core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_type', 20)->default('person');
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('person', 'application'))");

        Schema::table('integration_clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('integration_clients', function (Blueprint $table): void {
            $table->dropColumn('user_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('account_type');
        });
    }
};
