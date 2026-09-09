<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Support\Modules\Contracts\DaftarLaporan;
use App\Support\Modules\Contracts\PenyediaLaporanModul;

/**
 * Module mana yang menyediakan laporannya sendiri di dalam proses ini.
 *
 * Ini arah yang berlawanan dengan kontrak Core lainnya. `PenerbitNomor`, `KalenderFiskal`,
 * dan `MesinWorkflow` adalah Core yang melayani module; yang ini module yang melayani Core.
 * Karena itu daftarnya tidak bisa berupa binding tunggal pada container: ada lebih dari satu
 * module, dan yang menentukan siapa dipanggil adalah `app_id` laporannya.
 *
 * Isinya ditulis penyedia layanan tiap module saat boot. Tidak ada daftar yang ditulis
 * tangan di Core: sebuah daftar pusat berarti setiap module baru menyentuh berkas yang sama,
 * setiap cabang membentrokkannya, dan module yang lupa didaftarkan gagal dengan cara yang
 * membingungkan.
 *
 * Module yang tidak terdaftar bukan kesalahan. Ia berarti app di luar proses, dan
 * laporannya diambil lewat HTTP seperti sebelumnya.
 */
final class DaftarLaporanModul implements DaftarLaporan
{
    /** @var array<string, PenyediaLaporanModul> */
    private array $penyedia = [];

    public function daftarkan(PenyediaLaporanModul $penyedia): void
    {
        $this->penyedia[$penyedia->idModule()] = $penyedia;
    }

    public function untuk(string $idModule): ?PenyediaLaporanModul
    {
        return $this->penyedia[$idModule] ?? null;
    }

    /**
     * Id module yang terdaftar, diurutkan supaya keluarannya tetap sama antar proses.
     *
     * @return list<string>
     */
    public function idTerdaftar(): array
    {
        $id = array_keys($this->penyedia);
        sort($id);

        return $id;
    }
}
