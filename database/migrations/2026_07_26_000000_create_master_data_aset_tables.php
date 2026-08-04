<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // m_entitas_aset menjadi akar rantai klasifikasi. Foreign key anak memakai
        // (tenant_id, id) supaya induk lintas tenant tertolak oleh database, bukan
        // hanya oleh validasi aplikasi.
        Schema::table('m_entitas_aset', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id']);
        });

        $this->createMaster('m_group_aset', referencedByChild: true);
        $this->createMaster('m_kategori_aset', parentTable: 'm_group_aset', parentColumn: 'group_aset_id', referencedByChild: true);
        $this->createMaster('m_jenis_aset', parentTable: 'm_kategori_aset', parentColumn: 'kategori_aset_id', referencedByChild: true);
        Schema::table('m_entitas_aset', function (Blueprint $table): void {
            $table->ulid('jenis_aset_id');
            $table->index(['tenant_id', 'jenis_aset_id']);
            $table->foreign(['tenant_id', 'jenis_aset_id'])
                ->references(['tenant_id', 'id'])
                ->on('m_jenis_aset')
                ->restrictOnDelete();
        });
        $this->createMaster('m_kondisi_aset');
        $this->createMaster('m_pabrikan_aset');
        $this->createMaster('m_item_checklist_maintenance');
        $this->createMaster('m_analisa_maintenance');
    }

    public function down(): void
    {
        foreach ([
            'm_analisa_maintenance',
            'm_item_checklist_maintenance',
            'm_pabrikan_aset',
            'm_kondisi_aset',
            'm_jenis_aset',
            'm_kategori_aset',
            'm_group_aset',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('m_entitas_aset', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'jenis_aset_id']);
            $table->dropIndex(['tenant_id', 'jenis_aset_id']);
            $table->dropColumn('jenis_aset_id');
            $table->dropUnique(['tenant_id', 'id']);
        });
    }

    private function createMaster(
        string $table,
        ?string $parentTable = null,
        ?string $parentColumn = null,
        bool $referencedByChild = false,
    ): void {
        Schema::create($table, function (Blueprint $blueprint) use ($parentTable, $parentColumn, $referencedByChild): void {
            $blueprint->ulid('id')->primary();
            $blueprint->ulid('tenant_id')->index();
            $blueprint->string('creation_key', 160);
            if ($parentColumn) {
                $blueprint->ulid($parentColumn);
            }
            $blueprint->string('kode', 50);
            $blueprint->string('nama', 150);
            $blueprint->text('keterangan')->nullable();
            $blueprint->boolean('aktif')->default(true);
            $blueprint->softDeletes();
            $blueprint->timestamps();

            $blueprint->unique(['tenant_id', 'kode']);
            $blueprint->unique(['tenant_id', 'creation_key']);
            if ($referencedByChild) {
                $blueprint->unique(['tenant_id', 'id']);
            }
            if ($parentTable && $parentColumn) {
                $blueprint->index(['tenant_id', $parentColumn]);
                $blueprint->foreign(['tenant_id', $parentColumn])
                    ->references(['tenant_id', 'id'])
                    ->on($parentTable)
                    ->restrictOnDelete();
            }
        });
    }
};
