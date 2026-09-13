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
 * bukan lewat email: email dapat berganti di penyedia, `sub` tidak. Email hanya dipakai **sekali**,
 * saat penautan pertama, dan hanya bila penyedia menyatakannya terverifikasi.
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
            $table->index('user_id');
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
