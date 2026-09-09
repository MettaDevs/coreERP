<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Listeners;

use App\Support\Modules\Contracts\TenantDisiapkan;
use Modules\Apperp\ManagementAset\Services\ProvisionIndonesiaStarterData;

/**
 * Mengisi data awal Indonesia untuk tenant yang baru dibuat.
 *
 * Menggantikan `TenantProvisioningController`, yang menerima fakta yang sama sebagai
 * permintaan HTTP bertanda tangan HMAC. Isinya nyaris tidak berubah; yang hilang hanyalah
 * lapisan milik HTTP — verifikasi tanda tangan, validasi amplop, dan jawaban JSON.
 *
 * **Menyaring, bukan memvalidasi.** Event ini sampai ke setiap listener di runtime,
 * termasuk milik module yang tidak dibeli tenant tersebut. Amplop yang bukan urusan module
 * ini dilewati diam-diam; menolaknya sebagai kesalahan berarti pendaftaran usaha gagal
 * karena ada module yang tidak dibeli.
 *
 * **Aman diulang, dan itu bukan janji kosong.** `forTenant` tidak menimpa data yang sudah
 * ada; itu perilaku yang memang dimilikinya sejak ia dipanggil lewat HTTP, ketika pengiriman
 * ulang adalah kejadian biasa. Jadi tidak ada tabel dedup di sini — yang menahan pengulangan
 * adalah penyedianya sendiri, bukan sebuah catatan terpisah yang bisa menyimpang darinya.
 *
 * **Yang berubah dan perlu diketahui: kegagalan sekarang membatalkan pendaftaran usahanya.**
 * Event ini dipancarkan di dalam transaksi yang membuat tenant, jadi penyediaan yang gagal
 * ikut membatalkan tenantnya. Dulu jalur HTTP berjalan setelahnya dan terpisah, sehingga
 * penyediaan yang gagal meninggalkan tenant hidup tanpa satu pun klasifikasi fiskal,
 * profil penyusutan, atau setup maintenance — rusak dengan cara yang tidak terlihat sampai
 * pemakainya membuka layar pertama. Gagal seketika lebih baik daripada itu.
 */
final class SiapkanDataAwalTenant
{
    private const ID_MODULE = 'management-aset';

    public function __construct(private readonly ProvisionIndonesiaStarterData $penyedia) {}

    public function handle(TenantDisiapkan $event): void
    {
        $idModule = $event->data['app_ids'] ?? null;

        if (! is_array($idModule) || ! in_array(self::ID_MODULE, $idModule, true)) {
            return;
        }

        $this->penyedia->forTenant($event->tenantId);
    }
}
