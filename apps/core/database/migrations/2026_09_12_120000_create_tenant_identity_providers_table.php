<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tempat setelan penyedia identitas per tenant — tempatnya, bukan integrasinya.
 *
 * Repo ini **tidak punya SSO sama sekali** hari ini: nol Socialite, nol SAML, nol OIDC. Yang
 * terpasang Fortify beserta passkey, dan itu *passwordless*, bukan *single sign-on*. Audit repo
 * sendiri menamainya `SEC-14`.
 *
 * Jadi tabel ini tidak menjalankan apa pun. Yang dibelinya satu hal: **jalur masuk berhenti
 * berasumsi hanya ada kata sandi.** Menambahkannya kelak berarti membongkar setiap layar masuk yang
 * telanjur ditulis dengan asumsi itu; menyiapkan tempatnya sekarang berharga satu migration.
 *
 * ## Tiga keadaan, dan kenapa dua mode harus didukung sekaligus
 *
 * - `lokal` — kata sandi di sistem ini, seperti hari ini.
 * - `bersama` — penyedia identitas milik vendor, satu untuk semua pelanggan. Murah, dan cukup untuk
 *   pelanggan kecil.
 * - `sendiri` — penyedia milik pelanggan. Pelanggan korporat menuntutnya, karena mereka mau
 *   mencabut akses karyawan dari direktori mereka sendiri, bukan meminta kita melakukannya.
 *
 * Pemilik produk memutuskan **keduanya** harus mungkin, dan itu yang membuat alamat per tenant
 * menjadi syarat dan bukan hiasan: domainlah yang memberi tahu sistem penyedia mana yang dipakai
 * sebelum orangnya mengetik apa pun.
 *
 * ## Rahasianya tidak ditaruh di sini
 *
 * Kolom `setelan` sengaja hanya memuat yang tidak rahasia — issuer, client id, alamat penemuan.
 * Client secret dan kunci penandatangan adalah rahasia, dan rahasia yang tersimpan sebagai kolom
 * biasa akan ikut terbaca setiap kali seseorang menyalin database untuk keperluan lain. Bentuk
 * penyimpanannya keputusan tersendiri yang belum diambil, dan menebaknya sekarang berarti
 * menaruhnya di tempat yang salah dengan biaya pemindahan penuh kemudian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_identity_providers', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Satu baris per tenant. Ditegakkan unique, bukan diniatkan: dua baris untuk satu
            // tenant berarti pertanyaan "lewat mana orang ini masuk" punya dua jawaban, dan yang
            // dipakai akan ditentukan urutan baris — yaitu kebetulan.
            $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('mode', 20);
            $table->string('protokol', 20)->nullable();
            $table->jsonb('setelan')->default('{}');

            // Domain email yang diarahkan ke penyedia ini. Kosong berarti tidak ada pengarahan
            // otomatis, dan orangnya memilih sendiri di layar masuk.
            $table->jsonb('domain_email')->default('[]');

            $table->boolean('aktif')->default(false);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE tenant_identity_providers ADD CONSTRAINT tenant_idp_mode_dikenal CHECK (mode IN ('lokal', 'bersama', 'sendiri'))");
        DB::statement("ALTER TABLE tenant_identity_providers ADD CONSTRAINT tenant_idp_protokol_dikenal CHECK (protokol IS NULL OR protokol IN ('oidc', 'saml'))");

        // Mode `sendiri` tanpa protokol adalah baris yang tidak dapat dipakai apa pun, dan ia akan
        // terbaca sebagai "SSO sudah disetel" oleh siapa pun yang melihat daftarnya.
        DB::statement("ALTER TABLE tenant_identity_providers ADD CONSTRAINT tenant_idp_sendiri_berprotokol CHECK (mode <> 'sendiri' OR protokol IS NOT NULL)");

        // Dan yang aktif wajib bukan `lokal`: menyalakan bendera pada mode lokal tidak berarti
        // apa-apa, tetapi ia membuat layar setelan menampilkan keadaan yang tidak ada.
        DB::statement("ALTER TABLE tenant_identity_providers ADD CONSTRAINT tenant_idp_aktif_bukan_lokal CHECK (aktif = false OR mode <> 'lokal')");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_identity_providers');
    }
};
