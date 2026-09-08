<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Padanan "Attribute types" di Dynamics 365 F&O.
        //
        // Inilah jawaban atas kebutuhan yang dulu memaksa penambahan tingkat klasifikasi:
        // client yang ingin membedakan aset lebih rinci menambah atribut, bukan menambah
        // tabel. Tenant dapat membuatnya sendiri tanpa developer.
        Schema::create('m_tipe_atribut', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('creation_key', 160);
            $table->string('kode', 50);
            $table->string('nama', 150);
            $table->text('keterangan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->string('data_type', 20);
            // Satuan hanya pelengkap tampilan, misalnya "liter" atau "kg".
            $table->string('satuan', 50)->nullable();
            // Batas untuk tipe rentang nilai.
            $table->decimal('min_value', 24, 6)->nullable();
            $table->decimal('max_value', 24, 6)->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'kode']);
            $table->unique(['tenant_id', 'creation_key']);
        });

        // Pilihan untuk atribut bertipe daftar tetap. Tabel tersendiri, bukan JSON,
        // supaya nilai yang dipilih aset dapat ditegakkan foreign key.
        Schema::create('m_tipe_atribut_nilai', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('tipe_atribut_id');
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->string('nilai', 150);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'tipe_atribut_id', 'nilai']);
            $table->index(['tenant_id', 'tipe_atribut_id', 'urutan']);
            $table->foreign(['tenant_id', 'tipe_atribut_id'])->references(['tenant_id', 'id'])->on('m_tipe_atribut')->restrictOnDelete();
        });

        // Atribut menempel pada jenis aset, sama seperti F&O menempelkan attribute type
        // pada asset type. Aset mewarisi atribut dari jenisnya.
        Schema::create('m_jenis_aset_atribut', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('jenis_aset_id');
            $table->ulid('tipe_atribut_id');
            $table->boolean('wajib')->default(false);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'jenis_aset_id', 'tipe_atribut_id']);
            $table->foreign(['tenant_id', 'jenis_aset_id'])->references(['tenant_id', 'id'])->on('m_jenis_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tipe_atribut_id'])->references(['tenant_id', 'id'])->on('m_tipe_atribut')->restrictOnDelete();
        });

        // Nilai atribut per aset. Kolom dibuat bertipe, bukan satu kolom teks, supaya
        // database dapat menegakkan tipe dan laporan dapat menyaring rentang angka
        // dengan indeks. Hanya satu kolom terisi per baris, sesuai `data_type`.
        Schema::create('tr_aset_atribut', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('asset_id');
            $table->ulid('tipe_atribut_id');
            $table->text('nilai_text')->nullable();
            $table->decimal('nilai_number', 24, 6)->nullable();
            $table->boolean('nilai_boolean')->nullable();
            $table->date('nilai_date')->nullable();
            $table->ulid('tipe_atribut_nilai_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'asset_id', 'tipe_atribut_id']);
            $table->index(['tenant_id', 'tipe_atribut_id', 'nilai_number']);
            $table->foreign(['tenant_id', 'asset_id'])->references(['tenant_id', 'id'])->on('tr_penerimaan_aset')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tipe_atribut_id'])->references(['tenant_id', 'id'])->on('m_tipe_atribut')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tipe_atribut_nilai_id'])->references(['tenant_id', 'id'])->on('m_tipe_atribut_nilai')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_aset_atribut');
        Schema::dropIfExists('m_jenis_aset_atribut');
        Schema::dropIfExists('m_tipe_atribut_nilai');
        Schema::dropIfExists('m_tipe_atribut');
    }
};
