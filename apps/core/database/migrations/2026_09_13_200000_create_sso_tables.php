<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua tabel untuk masuk lewat penyedia identitas bersama. Keduanya sisi pusat.
 *
 * ## `external_identities`
 *
 * Satu baris = "subjek `sub` dari penerbit `iss` adalah user ini". Dicocokkan lewat pasangan itu,
 * **tidak pernah lewat email**.
 *
 * Barisnya hanya lahir ketika orangnya sudah membuktikan diri pemilik akun CoreERP — masuk dengan
 * kata sandinya, mengonfirmasinya lagi di layar keamanan, lalu menekan "Hubungkan SSO". Klaim
 * `email_verified` dari penyedia tidak cukup: diperiksa di kode penyedia pada 14 September 2026,
 * pendaftaran mandiri di sana langsung menulis `email_verified_at = now()` tanpa verifikasi apa
 * pun, jadi siapa saja dapat mendaftar dengan email orang lain dan memperoleh klaim itu.
 *
 * ## `sso_login_attempts`
 *
 * Satu baris = satu upacara masuk, dari tombol di alamat tenant sampai sesi berdiri di alamat itu.
 *
 * Disimpan di database, bukan di sesi, karena upacaranya menyeberangi dua alamat. Tombolnya ditekan
 * di `<tenant>.<domain>`, sedangkan penyedia hanya mau mengembalikan orang ke **satu** alamat balik
 * yang terdaftar persis — `<domain>/sso/callback` — dan cookie sesi di dua alamat itu terpisah. Jadi
 * `state`, `nonce`, dan `code_verifier` tidak dapat menunggu di sesi mana pun yang terbaca di kedua
 * sisi.
 *
 * `browser_secret_hash` yang mengikat ujung dan pangkalnya ke peramban yang sama. Rahasianya ditaruh
 * sebagai cookie di alamat tenant saat tombol ditekan, dan diperiksa lagi di alamat tenant saat sesi
 * diserahkan. Tanpa itu, penyerang dapat memulai upacara di perambannya sendiri lalu memancing
 * korban menyelesaikannya — dan korban masuk sebagai akun penyerang tanpa menyadarinya.
 *
 * Yang disimpan hanya hash untuk setiap nilai yang berfungsi sebagai kunci: bocornya baris ini tidak
 * boleh cukup untuk menyelesaikan upacara orang lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer');
            $table->string('subject');
            // Email saat penautan pertama, untuk jejak. Bukan kunci pencarian.
            $table->string('email_at_link')->nullable();
            $table->timestamps();

            $table->unique(['issuer', 'subject']);
            // Satu akun CoreERP satu akun SSO per penerbit — ditegakkan di sini, bukan hanya
            // diperiksa di kode. Dua upacara hubungkan yang berbalapan tidak boleh sama-sama menang.
            $table->unique(['user_id', 'issuer']);
        });

        Schema::create('sso_login_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('state_hash', 64)->unique();
            $table->string('browser_secret_hash', 64);
            $table->string('nonce', 64);
            $table->text('code_verifier');
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            // Alamat tenant tempat tombolnya ditekan, berikut skema dan portanya. Tujuan serah terima.
            $table->string('return_origin');
            $table->string('redirect_uri');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            // Terisi hanya pada upacara "hubungkan": akun CoreERP yang sedang masuk dan meminta
            // akun SSO-nya ditautkan. Kosong berarti upacara masuk biasa.
            $table->foreignId('link_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Subjek yang sudah terverifikasi di `callback`, menunggu dihubungkan di `handoff`.
            //
            // Hubungannya sengaja TIDAK dibuat di `callback`. Di sana belum ada bukti bahwa peramban
            // yang kembali dari penyedia adalah peramban yang memulai upacara — cookie itu hanya
            // terbaca di alamat tenant. Membuatnya di `callback` berarti penyerang dapat memulai
            // "hubungkan" dari akunnya sendiri, mengirim tautan penyedia ke korban, dan akun SSO
            // korban tertaut ke akun penyerang begitu korban mengkliknya.
            $table->string('subject')->nullable();
            $table->string('subject_email')->nullable();
            $table->string('handoff_token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_login_attempts');
        Schema::dropIfExists('external_identities');
    }
};
