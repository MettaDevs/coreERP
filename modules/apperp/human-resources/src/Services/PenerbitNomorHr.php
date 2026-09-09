<?php

namespace Modules\Apperp\HumanResources\Services;

use App\Support\Modules\Contracts\PenerbitNomor;
use RuntimeException;
use Throwable;

/**
 * Penerbitan nomor lewat kontrak Core, bukan lewat HTTP.
 *
 * Menggantikan klien yang dulu mengirim permintaan ke `number-sequences/{referensi}/issue`.
 * Yang berubah bukan kecepatannya melainkan **kapan nomor itu batal**: penerbitan sekarang
 * berjalan pada koneksi database yang sama dengan baris yang sedang disimpan, jadi ia ikut di
 * dalam transaksi baris itu. Pekerja yang gagal disimpan tidak lagi meninggalkan nomor induk
 * yang terlanjur terbit, dan tidak ada lompatan nomor yang harus dijelaskan ke pemeriksa.
 * Lewat HTTP hal itu tidak mungkin: permintaannya selesai di luar transaksi module.
 *
 * Tanda tangan `issue()` dipertahankan persis seperti milik klien lama supaya pemanggilnya
 * tidak ikut berubah.
 */
final class PenerbitNomorHr
{
    public function __construct(private readonly PenerbitNomor $penerbit) {}

    /**
     * Menerbitkan satu nomor untuk referensi milik module ini.
     *
     * `$key` adalah kunci idempoten: dua permintaan dengan kunci yang sama menerima nomor yang
     * sama, dan itulah yang membuat pengiriman ulang tidak pernah memakan dua nomor.
     */
    public function issue(string $reference, string $tenantId, string $key): string
    {
        try {
            $hasil = $this->penerbit->terbitkan(
                ['tenant_id' => $tenantId, 'app_id' => 'human-resources'],
                $reference,
                $key,
            );
        } catch (Throwable $kegagalan) {
            // Pesannya dipertahankan apa adanya karena ia sudah muncul di layar pengguna.
            // Yang berubah hanya sebab yang mungkin: kegagalan di sini tidak pernah lagi
            // berarti "Core tidak dapat dihubungi" — Core ada di proses ini — melainkan
            // permintaannya sendiri tidak dapat dipenuhi, misalnya referensi yang belum
            // punya urutan nomor untuk tenant ini.
            throw new RuntimeException('Nomor belum dapat diterbitkan.', previous: $kegagalan);
        }

        if ($hasil['number'] === '') {
            throw new RuntimeException('Nomor belum dapat diterbitkan.');
        }

        return $hasil['number'];
    }
}
