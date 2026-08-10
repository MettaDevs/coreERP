<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master klasifikasi aset dibuat datar dan saling lepas, mengikuti model
        // Dynamics 365 F&O: kedalaman klasifikasi ditentukan isi data tenant, bukan
        // skema. Aset menunjuk group (sumbu finansial: penyusutan, GL, penomoran) dan
        // jenis (sumbu teknis: maintenance, atribut) secara langsung dan sejajar.
        // Tidak ada rantai berjenjang di antara keduanya, sehingga tenant yang hanya
        // mengenal satu tingkat klasifikasi tidak perlu mengisi tingkat yang tidak ada.
        //
        // Foreign key selalu memakai (tenant_id, id) supaya induk lintas tenant tertolak
        // oleh database, bukan hanya oleh validasi aplikasi.
        $this->createMaster('m_group_aset', referencedByChild: true);
        $this->createMaster('m_jenis_aset', referencedByChild: true);
        $this->createMaster('m_kondisi_aset', referencedByChild: true);
        $this->createMaster('m_pabrikan_aset', referencedByChild: true);
        $this->createMaster('m_item_checklist_maintenance');
        $this->createMaster('m_analisa_maintenance');

        // Katalog model barang milik satu pabrikan; padanan "Manufacturers and models"
        // di F&O. Pabrikan wajib karena sebuah model selalu milik satu pabrikan. Jenis
        // opsional supaya katalog dapat mulai diisi sebelum klasifikasi teknis ditetapkan.
        // Keduanya induk yang saling lepas: tidak ada yang menyaring pilihan yang lain.
        $this->createMaster(
            'm_model_aset',
            parents: [
                'pabrikan_aset_id' => ['table' => 'm_pabrikan_aset', 'required' => true],
                'jenis_aset_id' => ['table' => 'm_jenis_aset', 'required' => false],
            ],
            referencedByChild: true,
            columns: function (Blueprint $blueprint): void {
                $blueprint->string('model_number', 150)->nullable();
            },
        );
    }

    public function down(): void
    {
        foreach ([
            'm_model_aset',
            'm_analisa_maintenance',
            'm_item_checklist_maintenance',
            'm_pabrikan_aset',
            'm_kondisi_aset',
            'm_jenis_aset',
            'm_group_aset',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  array<string, array{table: string, required: bool}>  $parents
     * @param  null|callable(Blueprint): void  $columns
     */
    private function createMaster(
        string $table,
        array $parents = [],
        bool $referencedByChild = false,
        ?callable $columns = null,
    ): void {
        Schema::create($table, function (Blueprint $blueprint) use ($parents, $referencedByChild, $columns): void {
            $blueprint->ulid('id')->primary();
            $blueprint->ulid('tenant_id')->index();
            $blueprint->string('creation_key', 160);
            foreach ($parents as $column => $parent) {
                $blueprint->ulid($column)->nullable(! $parent['required']);
            }
            $blueprint->string('kode', 50);
            $blueprint->string('nama', 150);
            $blueprint->text('keterangan')->nullable();
            $blueprint->boolean('aktif')->default(true);
            if ($columns) {
                $columns($blueprint);
            }
            $blueprint->softDeletes();
            $blueprint->timestamps();

            $blueprint->unique(['tenant_id', 'kode']);
            $blueprint->unique(['tenant_id', 'creation_key']);
            if ($referencedByChild) {
                $blueprint->unique(['tenant_id', 'id']);
            }
            foreach ($parents as $column => $parent) {
                $blueprint->index(['tenant_id', $column]);
                $blueprint->foreign(['tenant_id', $column])
                    ->references(['tenant_id', 'id'])
                    ->on($parent['table'])
                    ->restrictOnDelete();
            }
        });
    }
};
