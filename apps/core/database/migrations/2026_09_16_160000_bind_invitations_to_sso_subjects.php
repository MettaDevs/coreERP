<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undangan yang terikat pada satu akun SSO, di samping kode anonim yang sudah ada.
 *
 * Undangan hari ini adalah kode anonim yang dapat dipakai berulang: siapa pun yang memegangnya
 * mengetik nama, email, dan kata sandi, lalu akun lokal lahir. Pemilik produk meminta jalur kedua —
 * mengundang **orang tertentu**, yang emailnya wajib sudah terdaftar di penyedia SSO.
 *
 * ## Yang diikat adalah subjek, bukan email
 *
 * Godaan yang paling mudah adalah menyimpan email yang diundang, lalu mencocokkannya dengan klaim
 * `email` saat orangnya masuk. Repo ini sudah menolak cara itu sekali, dengan alasan yang diukur
 * pada 14 September 2026: penyedia menulis `email_verified_at` saat pendaftaran mandiri **tanpa
 * benar-benar memverifikasi**, sehingga siapa pun yang mendaftar dengan email orang lain akan
 * dianggap orang itu. Aturannya tertulis di `2026_09_13_200000_create_sso_tables.php` dan dijaga
 * satu test: akun SSO yang belum terhubung ditolak sekalipun emailnya sama persis.
 *
 * Yang membuat undangan ini tetap bisa dikerjakan tanpa melanggar aturan itu: klaim `sub` penyedia
 * adalah id numerik penggunanya, dan **angka yang sama** dikembalikan endpoint pencarian yang
 * dipanggil saat undangan dibuat. Jadi yang disimpan di sini `sso_subject`, dan yang dibandingkan
 * saat penukaran juga `sub` — bukan sekali pun emailnya. Kolom `sso_email_at_invite` ada untuk
 * ditampilkan dan untuk jejak, sama seperti `email_at_link` di `external_identities`.
 *
 * ## Kenapa yang terikat hanya sekali pakai
 *
 * Kode anonim sengaja dapat dipakai berulang, dan `RedeemInvitation` bahkan **menghidupkan kembali
 * keanggotaan yang dicabut** — untuk kode anonim itu memang niatnya. Pada undangan pribadi sifat itu
 * berubah arti: orang yang aksesnya baru saja dicabut operator dapat masuk lagi lewat tautan lamanya.
 * Karena itu undangan terikat ditandai `sso_redeemed_at` dan ditolak pada penukaran kedua.
 *
 * ## Upacara menyimpan id undangan, bukan kodenya
 *
 * `sso_login_attempts.invitation_id` menunjuk barisnya. Baris upacara yang bocor tidak boleh memuat
 * rahasia yang dapat ditukar siapa pun yang membacanya — dan untuk undangan terikat, kodenya memang
 * bukan rahasia yang menentukan: ia hanya memilih undangan mana, sementara yang membukanya `sub`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_codes', function (Blueprint $table): void {
            // Penerbit disimpan bersama subjeknya. Subjek tanpa penerbit adalah angka tanpa arti:
            // `42` di dua penyedia berbeda adalah dua orang berbeda, dan repo ini sudah menyiapkan
            // mode `sendiri` tempat penerbitnya memang berbeda per tenant.
            $table->string('sso_issuer')->nullable();
            $table->string('sso_subject')->nullable();

            // Ejaan dari penyedia, bukan ketikan operator. Keduanya hanya untuk ditampilkan.
            $table->string('sso_email_at_invite')->nullable();
            $table->string('sso_name_at_invite')->nullable();

            // Kapan pencarian menjawab bahwa orangnya ada. Jaraknya dengan hari ini adalah umur
            // jawaban itu: akun SSO dapat dinonaktifkan sesudahnya tanpa kita tahu.
            $table->timestamp('sso_checked_at')->nullable();

            // Kapan penyedia menerima permintaan mengirim emailnya. Kosong berarti undangan ada
            // tetapi belum ada surat yang keluar — keadaan yang wajar ketika SSO sedang mati, dan
            // yang harus terlihat di layar supaya operator tahu perlu mengirim ulang.
            $table->timestamp('sso_notified_at')->nullable();

            $table->timestamp('sso_redeemed_at')->nullable();

            // Id biasa, **tanpa** foreign key. `users` ada di sisi pusat sementara tabel ini ada di
            // sisi environment, dan anggaran foreign key yang menyeberang batas itu hanya boleh
            // turun — aturannya dijaga `FkMenyeberangBatasTest`. Yang menjaga pasangannya di sini
            // adalah CHECK di bawah, dan akun yang hilang membuat barisnya tetap terbaca sebagai
            // "sudah ditukar", yang memang yang terjadi.
            $table->unsignedBigInteger('sso_redeemed_by')->nullable();

            $table->index(['sso_issuer', 'sso_subject']);
        });

        // Undangan separuh terikat akan terbaca terikat di layar dan anonim saat ditukar. Dibuat
        // mustahil ditulis, bukan diperiksa di satu tempat yang kelak dilewati jalur kedua.
        DB::statement(<<<'SQL'
            ALTER TABLE invitation_codes ADD CONSTRAINT undangan_sso_utuh CHECK (
                (sso_issuer IS NULL AND sso_subject IS NULL AND sso_email_at_invite IS NULL)
                OR (sso_issuer IS NOT NULL AND sso_subject IS NOT NULL AND sso_email_at_invite IS NOT NULL)
            )
        SQL);

        // Hanya undangan terikat yang dapat habis; kode anonim tidak pernah "dipakai" dalam arti ini.
        DB::statement(<<<'SQL'
            ALTER TABLE invitation_codes ADD CONSTRAINT undangan_sso_tertukar_hanya_bila_terikat CHECK (
                sso_redeemed_at IS NULL OR sso_subject IS NOT NULL
            )
        SQL);

        // Tertukar tanpa penukar, atau sebaliknya, adalah baris yang tidak dapat dijelaskan kepada
        // siapa pun yang membacanya enam bulan kemudian.
        DB::statement(<<<'SQL'
            ALTER TABLE invitation_codes ADD CONSTRAINT undangan_sso_tertukar_lengkap CHECK (
                (sso_redeemed_at IS NULL) = (sso_redeemed_by IS NULL)
            )
        SQL);

        // Paling banyak satu undangan terbuka per orang per tenant. Dua operator yang mengundang
        // orang yang sama harus mendapat kalimat penolakan, bukan dua baris hidup yang tidak pernah
        // direkonsiliasi siapa pun. Parsial, supaya yang dicabut dan yang sudah dipakai menumpuk
        // seperti biasa.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX undangan_sso_satu_yang_terbuka
                ON invitation_codes (tenant_id, sso_issuer, sso_subject)
                WHERE sso_subject IS NOT NULL AND revoked_at IS NULL AND sso_redeemed_at IS NULL
        SQL);

        Schema::table('sso_login_attempts', function (Blueprint $table): void {
            // Juga tanpa foreign key, dan kali ini arahnya yang terlarang: `sso_login_attempts`
            // milik sisi pusat, `invitation_codes` milik sisi environment, dan sebuah environment
            // boleh disalin, dikosongkan, lalu dihapus — constraint dari sisi pusat akan menggantung
            // ke baris yang sudah tidak ada. Upacara yang menunjuk undangan yang lenyap ditolak di
            // kode dengan kalimat yang sama seperti undangan yang dicabut.
            $table->ulid('invitation_id')->nullable();
            $table->index('invitation_id');
        });
    }

    public function down(): void
    {
        Schema::table('sso_login_attempts', function (Blueprint $table): void {
            $table->dropIndex(['invitation_id']);
            $table->dropColumn('invitation_id');
        });

        DB::statement('DROP INDEX IF EXISTS undangan_sso_satu_yang_terbuka');

        foreach (['undangan_sso_utuh', 'undangan_sso_tertukar_hanya_bila_terikat', 'undangan_sso_tertukar_lengkap'] as $constraint) {
            DB::statement('ALTER TABLE invitation_codes DROP CONSTRAINT IF EXISTS '.$constraint);
        }

        Schema::table('invitation_codes', function (Blueprint $table): void {
            $table->dropIndex(['sso_issuer', 'sso_subject']);
            $table->dropColumn([
                'sso_redeemed_by',
                'sso_issuer',
                'sso_subject',
                'sso_email_at_invite',
                'sso_name_at_invite',
                'sso_checked_at',
                'sso_notified_at',
                'sso_redeemed_at',
            ]);
        });
    }
};
