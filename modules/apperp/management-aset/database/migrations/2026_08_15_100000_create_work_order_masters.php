<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master pendukung work order. Seluruhnya datar dan saling lepas: tidak ada
        // rantai di antara mereka, dan work order menunjuk masing-masing secara langsung.
        //
        // Bidang keahlian, sebab kerusakan, dan tindakan perbaikan sebelumnya berupa
        // pilihan yang ditulis di UI atau tidak ada sama sekali. Dipindahkan ke master
        // supaya tenant dapat menambah nilainya tanpa merilis ulang aplikasi, dan supaya
        // hasil pekerjaan dapat dihitung, bukan sekadar dibaca sebagai teks bebas.
        $this->createMaster('m_trade');
        $this->createMaster('m_sebab_kerusakan');
        $this->createMaster('m_tindakan_perbaikan');

        // Tingkat layanan menentukan urgensi penanganan. `urutan` yang lebih kecil berarti
        // lebih mendesak, mengikuti cara Dynamics 365 memakai service level 1 sebagai yang
        // tertinggi. Ia hanya mengurutkan dan menyaring; tidak ada perhitungan tenggat di
        // fase ini, jadi target waktu sengaja belum disimpan.
        $this->createMaster('m_tingkat_layanan', function (Blueprint $table): void {
            $table->unsignedInteger('urutan')->default(0);
        });

        // Tipe work order membawa aturan, bukan sekadar label. Padanan "Work order types"
        // di Dynamics 365: satu baris master menentukan apa yang wajib diisi sebelum
        // pekerjaan boleh dinyatakan selesai. Aturan ini menjadi data tenant, bukan
        // percabangan di controller, sehingga tenant yang mewajibkan analisa kerusakan dan
        // tenant yang tidak dapat memakai kode yang sama.
        //
        // Kolom `work order lifecycle model`, `cost type`, dan `maintenance downtime` yang
        // ada di Dynamics sengaja belum dibuat: belum ada fitur yang membacanya, dan kolom
        // mati lebih menyesatkan daripada kolom yang belum ada.
        $this->createMaster('m_tipe_work_order', function (Blueprint $table): void {
            $table->boolean('satu_pekerja')->default(false);
            $table->boolean('wajib_sebab')->default(false);
            $table->boolean('wajib_tindakan')->default(false);
        });
    }

    public function down(): void
    {
        foreach ([
            'm_tipe_work_order',
            'm_tingkat_layanan',
            'm_tindakan_perbaikan',
            'm_sebab_kerusakan',
            'm_trade',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Bentuk master yang sama dengan `create_master_data_aset_tables`: kode diterbitkan
     * Number Sequence Core, satu tenant per record, arsip memakai soft delete.
     * `unique(tenant_id, id)` wajib ada supaya tabel anak dapat memakai foreign key
     * komposit dan induk lintas tenant ditolak database, bukan hanya oleh validasi.
     *
     * @param  null|callable(Blueprint): void  $columns
     */
    private function createMaster(string $table, ?callable $columns = null): void
    {
        Schema::create($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->ulid('id')->primary();
            $blueprint->ulid('tenant_id')->index();
            $blueprint->string('creation_key', 160);
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
            $blueprint->unique(['tenant_id', 'id']);
        });
    }
};
