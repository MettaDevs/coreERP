<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role Owner ditandai `is_owner` (SEC-22, TODO feed posting 7.4). Sampai sekarang ia hanya role bernama "Owner"
 * yang dibuat `RegisterBusiness`; sejak owner/admin dihapus dari keanggotaan, dialah satu-satunya jalan ke semua
 * duty, dan `OwnerRoleDuties` menyamakan duty-nya setiap kali katalog berubah. Nama tidak cukup sebagai penanda:
 * admin tenant boleh mengganti nama role.
 *
 * Satu Owner per tenant, dijaga indeks unik parsial. Role bernama "Owner" yang sudah ada — `roles` unik per
 * tenant dan nama, jadi paling banyak satu — menjadi Owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('is_owner')->default(false);
        });
        DB::statement('create unique index roles_satu_owner_per_tenant on roles (tenant_id) where is_owner');

        DB::table('roles')->where('name', 'Owner')->update(['is_owner' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::statement('drop index if exists roles_satu_owner_per_tenant');
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('is_owner');
        });
    }
};
