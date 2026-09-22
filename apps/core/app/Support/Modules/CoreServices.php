<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Services\Modules\DaftarAkunCore;
use App\Services\Modules\DaftarSatuanCore;
use App\Services\Modules\DirektoriOrganisasiCore;
use App\Services\Modules\KalenderFiskalCore;
use App\Services\Modules\KonteksTenantPermintaan;
use App\Services\Modules\MesinWorkflowCore;
use App\Services\Modules\PenerbitNomorCore;
use App\Services\Modules\PresisiMataUangCore;
use App\Services\Modules\SetelanPostingFinanceCore;
use App\Support\Modules\Contracts\DaftarAkun;
use App\Support\Modules\Contracts\DaftarLaporan;
use App\Support\Modules\Contracts\DaftarSatuan;
use App\Support\Modules\Contracts\DirektoriOrganisasi;
use App\Support\Modules\Contracts\KalenderFiskal;
use App\Support\Modules\Contracts\KonteksPermintaan;
use App\Support\Modules\Contracts\KonteksTenant;
use App\Support\Modules\Contracts\MesinWorkflow;
use App\Support\Modules\Contracts\PelaksanaUntukTenant;
use App\Support\Modules\Contracts\PenerbitNomor;
use App\Support\Modules\Contracts\PresisiMataUang;
use App\Support\Modules\Contracts\SetelanPostingFinance;
use App\Support\Reporting\DaftarLaporanModul;
use Illuminate\Contracts\Foundation\Application;

/**
 * Satu tempat yang menyebut seluruh permukaan Core yang boleh dipanggil module.
 *
 * Daftar ini adalah **kontraknya**. Menambah baris di sini adalah keputusan arsitektur:
 * setiap pasangan berarti Core berjanji tidak mengubah bentuk panggilan itu tanpa mengubah
 * antarmukanya. Module yang butuh sesuatu di luar daftar ini tidak boleh mengambil jalan
 * pintas ke kelas Core; ia mengusulkan antarmuka baru.
 *
 * Semua antarmuka menerima **id, bukan objek Core**. Module yang harus mengambil objek Core
 * lebih dulu sudah menyentuh model Core, dan batas yang dibuat daftar ini kembali kabur.
 */
final class CoreServices
{
    /** @var array<class-string, class-string> */
    public const PEMETAAN = [
        PenerbitNomor::class => PenerbitNomorCore::class,
        KalenderFiskal::class => KalenderFiskalCore::class,
        DaftarSatuan::class => DaftarSatuanCore::class,
        MesinWorkflow::class => MesinWorkflowCore::class,
        DirektoriOrganisasi::class => DirektoriOrganisasiCore::class,
        KonteksTenant::class => KonteksTenantPermintaan::class,
        // Berdiri sendiri di samping KonteksTenant, tidak digabung ke dalamnya. Tenant
        // menjawab "di mana boleh membaca", konteks permintaan menjawab "apa yang boleh
        // dilakukan"; menggabungkannya membuat satu antarmuka punya dua sumber data —
        // sesi untuk yang satu, atribut permintaan untuk yang lain — dan pintu yang
        // jawabannya bergantung pada bagian mana yang dipanggil bukan pintu yang jelas.
        KonteksPermintaan::class => ModuleRequestContext::class,
        // Satu-satunya pintu module untuk menjalankan sesuatu di luar permintaan HTTP:
        // perintah artisan, pekerja antrean, dan test yang memanggil layanannya langsung.
        // Tanpa ini module harus menyebut kelas Core yang menyimpan tenant aktif, dan
        // batas yang berbunyi satu kalimat langsung runtuh.
        PelaksanaUntukTenant::class => PelaksanaTenant::class,
        // Feed posting finance: module yang menyusun jurnal membaca kebijakan penyelesaian
        // dan cutover entitas legal, dan membulatkan nilai dengan presisi yang sama dengan
        // yang dipakai penerbit posting.
        SetelanPostingFinance::class => SetelanPostingFinanceCore::class,
        PresisiMataUang::class => PresisiMataUangCore::class,
        // Feed posting finance: akun milik aplikasi finance pelanggan, dipilih di pemetaan
        // posting module dan dibaca ulang setiap kali posting terbit.
        DaftarAkun::class => DaftarAkunCore::class,
    ];

    /**
     * Kontrak yang **module** penuhi untuk Core, bukan sebaliknya.
     *
     * Dipisahkan dari `PEMETAAN` karena cara mengikatnya berbeda dan bedanya menentukan
     * apakah ia bekerja sama sekali: yang di atas dibuat baru tiap kali dipakai, sedangkan
     * daftar isian harus satu benda untuk seluruh proses. Diikat dengan `bind`, tiap
     * pendaftaran dari penyedia layanan module akan masuk ke salinan yang langsung dibuang,
     * dan Core melihat daftar kosong tanpa satu pun kesalahan.
     *
     * Alias dipasang ke arah kelas Core-nya, bukan sebaliknya, supaya Core yang membaca
     * daftar dan module yang mengisinya benar-benar memegang benda yang sama.
     *
     * @var array<class-string, class-string>
     */
    public const PEMETAAN_TUNGGAL = [
        DaftarLaporan::class => DaftarLaporanModul::class,
    ];

    public static function daftarkan(Application $app): void
    {
        foreach (self::PEMETAAN as $antarmuka => $pelaksana) {
            $app->bind($antarmuka, $pelaksana);
        }

        foreach (self::PEMETAAN_TUNGGAL as $antarmuka => $pelaksana) {
            $app->singleton($pelaksana);
            $app->alias($pelaksana, $antarmuka);
        }
    }
}
