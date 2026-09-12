<?php

declare(strict_types=1);

namespace App\Models;

use App\Providers\AppServiceProvider;
use App\Support\ControlPlane\OwnedByControlPlane;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * Passkey milik paket, ditandai sebagai tabel sisi pusat.
 *
 * ## Kenapa subclass, dan bukan sekadar menandai `User`
 *
 * `User` sudah memakai `OwnedByControlPlane`, dan itu tidak cukup. Trait itu menimpa
 * `getConnectionName()`, sedangkan Eloquent memberi koneksi kepada model terkait dari **properti**
 * `$connection` — lihat `Model::newRelatedInstance()`, yang memanggil
 * `setConnection($this->connection)`. Propertinya null, jadi `$user->passkeys()` jatuh ke koneksi
 * bawaan; dan sejak `ResolveEnvironment` menggeser koneksi bawaan, "bawaan" berarti database
 * lingkungan.
 *
 * Akibatnya persis kegagalan yang paling sulit dibaca: pelanggan yang masuk dengan passkey dari
 * alamat lingkungannya sendiri tidak menemukan passkey miliknya, dan pesannya berbunyi seperti
 * kunci yang salah alih-alih tabel yang salah tempat.
 *
 * ## Kenapa bukan mewariskan koneksi ke setiap relasi
 *
 * Itu tambalan yang terlihat lebih murah dan justru salah. Sebuah model sisi pusat boleh punya
 * relasi ke data tenant — `User` ke ekspor laporannya, misalnya — dan pewarisan buta akan menyeret
 * yang itu ikut ke database pusat. Yang benar menandai model terkaitnya satu per satu, dan paket
 * ini memang menyediakan pintunya: `Passkeys::usePasskeyModel()`, dipasang di
 * {@see AppServiceProvider}.
 *
 * ## Yang belum benar, dan bukan di berkas ini
 *
 * `fortify.passkeys.allowed_origins` masih berisi satu alamat yang diturunkan dari `app.url`,
 * sementara pelanggan masuk dari `<tenant>.contoh.co.id`. WebAuthn menerima `relying_party_id`
 * yang merupakan akhiran terdaftar dari origin — jadi satu id cukup untuk seluruh subdomain —
 * tetapi daftar origin yang diizinkan tetap diperiksa apa adanya. Sampai daftarnya diturunkan dari
 * domain dasar, passkey hanya bekerja pada satu alamat.
 */
final class Passkey extends BasePasskey
{
    use OwnedByControlPlane;
}
