<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Tempat module mendaftarkan laporannya kepada mesin laporan Core.
 *
 * Ini satu-satunya kontrak yang arahnya terbalik. Kontrak lain adalah Core yang melayani
 * module — penerbit nomor, kalender fiskal, mesin workflow; yang ini module yang melayani
 * Core. Antarmukanya tetap dibutuhkan justru karena arahnya terbalik: tanpa antarmuka,
 * penyedia layanan module harus menyebut kelas registry milik Core secara langsung, dan
 * batas yang berbunyi "module hanya boleh menyebut `App\Support\Modules\Contracts`" langsung
 * runtuh pada module pertama yang punya laporan.
 *
 * Dipakai dari penyedia layanan module, sekali saat boot:
 *
 *     $this->app->make(DaftarLaporan::class)->daftarkan($this->app->make(PenyediaLaporan::class));
 *
 * Module yang tidak mendaftar bukan kesalahan; ia berarti app di luar proses, dan Core
 * mengambil laporannya lewat HTTP seperti sebelumnya.
 */
interface DaftarLaporan
{
    public function daftarkan(PenyediaLaporanModul $penyedia): void;
}
