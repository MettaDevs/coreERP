<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('m_group_aset', 'entitas_aset_id')) {
            if (DB::table('m_entitas_aset')->exists() || DB::table('m_group_aset')->exists()) {
                throw new RuntimeException('Relasi group dan entitas aset tidak dapat dibalik otomatis karena sudah ada data. Pindahkan setiap entitas ke group yang benar sebelum menjalankan migration ini.');
            }

            Schema::table('m_group_aset', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'entitas_aset_id']);
                $table->dropIndex(['tenant_id', 'entitas_aset_id']);
                $table->dropColumn('entitas_aset_id');
            });
        }

        if (Schema::hasColumn('m_entitas_aset', 'group_aset_id')) {
            Schema::table('m_entitas_aset', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'group_aset_id']);
                $table->dropIndex(['tenant_id', 'group_aset_id']);
                $table->dropColumn('group_aset_id');
            });
        }

        if (! Schema::hasColumn('m_entitas_aset', 'jenis_aset_id')) {
            Schema::table('m_jenis_aset', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'id']);
            });
            Schema::table('m_entitas_aset', function (Blueprint $table): void {
                $table->ulid('jenis_aset_id');
                $table->index(['tenant_id', 'jenis_aset_id']);
                $table->foreign(['tenant_id', 'jenis_aset_id'])
                    ->references(['tenant_id', 'id'])
                    ->on('m_jenis_aset')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('m_entitas_aset', 'jenis_aset_id')) {
            Schema::table('m_entitas_aset', function (Blueprint $table): void {
                $table->dropForeign(['tenant_id', 'jenis_aset_id']);
                $table->dropIndex(['tenant_id', 'jenis_aset_id']);
                $table->dropColumn('jenis_aset_id');
            });
        }

        if (! Schema::hasColumn('m_group_aset', 'entitas_aset_id')) {
            Schema::table('m_group_aset', function (Blueprint $table): void {
                $table->ulid('entitas_aset_id');
                $table->index(['tenant_id', 'entitas_aset_id']);
                $table->foreign(['tenant_id', 'entitas_aset_id'])
                    ->references(['tenant_id', 'id'])
                    ->on('m_entitas_aset')
                    ->restrictOnDelete();
            });
        }
    }
};
