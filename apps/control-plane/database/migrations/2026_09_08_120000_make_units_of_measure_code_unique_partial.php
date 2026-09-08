<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // `units_of_measure` mengarsipkan barisnya lewat `deleted_at`, tetapi keunikan
        // (tenant_id, code) dibuat penuh, sehingga ia ikut menghitung baris yang sudah
        // diarsipkan. Akibatnya kode satuan yang sudah diarsipkan tidak pernah bisa dipakai
        // ulang: pengguna melihat "kode sudah dipakai" untuk kode yang tidak muncul di daftar
        // mana pun. Lihat "Penghapusan lunak" pada docs/dev/02-module-standard.md.
        //
        // Dua hal yang berbeda dari contoh pada standar app, dan keduanya diperiksa langsung
        // pada database, bukan disimpulkan dari membaca migrasi:
        //
        // 1. Keunikan lama ini adalah UNIQUE *constraint* (`pg_constraint.contype = 'u'`),
        //    bukan indeks unik lepas, karena ia lahir dari `$table->unique([...])`. Constraint
        //    tidak bisa dibuang dengan `DROP INDEX`; ia harus dibuang lewat `ALTER TABLE`.
        // 2. PostgreSQL tidak mengizinkan UNIQUE constraint memiliki klausa `WHERE`, jadi
        //    penggantinya wajib berupa indeks unik parsial, bukan constraint.
        //
        // Namanya sengaja dipakai ulang. PostgreSQL menyebut indeks unik biasa maupun parsial
        // dengan kalimat yang sama, `duplicate key value violates unique constraint "<nama>"`,
        // jadi mempertahankan nama membuat pesan yang dilihat pemanggil tidak berubah sama
        // sekali untuk kasus yang memang masih harus ditolak: dua baris hidup dengan kode sama.
        DB::statement('ALTER TABLE units_of_measure DROP CONSTRAINT units_of_measure_tenant_id_code_unique');
        DB::statement(
            'CREATE UNIQUE INDEX units_of_measure_tenant_id_code_unique '.
            'ON units_of_measure (tenant_id, code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        // down() ini sengaja tidak dibuat "aman", dan sengaja bisa gagal.
        //
        // Begitu indeks parsial berlaku, kode satuan yang sudah diarsipkan boleh dipakai ulang.
        // Sejak saat itu sepasang (tenant_id, code) yang sah bisa dimiliki satu baris hidup dan
        // satu baris terarsip sekaligus. Mengembalikan keunikan penuh pada keadaan seperti itu
        // mustahil dilakukan tanpa mengarang data: harus ada yang memutuskan baris mana yang
        // dibuang atau kodenya diubah, dan itu bukan keputusan yang boleh diambil migrasi
        // secara diam-diam.
        //
        // Maka pernyataan di bawah dibiarkan gagal apa adanya. PostgreSQL akan menolaknya
        // dengan `could not create unique index "units_of_measure_tenant_id_code_unique"`
        // beserta `Key (tenant_id, code)=(...) is duplicated`, yang justru menyebut baris
        // penyebabnya. Rapikan datanya lebih dulu, baru jalankan mundur lagi.
        DB::statement('DROP INDEX units_of_measure_tenant_id_code_unique');
        DB::statement(
            'ALTER TABLE units_of_measure '.
            'ADD CONSTRAINT units_of_measure_tenant_id_code_unique UNIQUE (tenant_id, code)'
        );
    }
};
