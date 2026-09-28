<?php

declare(strict_types=1);

namespace App\Support\Sso;

use RuntimeException;

/**
 * API pengelolaan penyedia SSO tidak dapat dipakai saat ini.
 *
 * Sengaja bukan `SsoFailure`. Kode `SsoFailure` adalah kosakata tertutup yang dicetak di halaman
 * masuk dari sebuah query string, dan daftarnya dijaga satu test supaya teks bebas tidak pernah
 * sampai ke layar. Kegagalan di sini justru sebaliknya: ia dibaca operator yang sudah masuk, di
 * dalam dialog, dan yang menolongnya adalah kalimat yang menyebut apa yang harus diperbaiki.
 *
 * Isinya karena itu boleh berupa kalimat — tetapi tidak pernah memuat client secret. Lihat
 * `SsoApiClient::gagal()`.
 */
final class SsoApiUnavailable extends RuntimeException {}
