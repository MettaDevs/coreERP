<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use App\Foundation\Currency\ModuleServices\PresisiMataUangCore;
use App\Foundation\FinancePosting\ModuleServices\DaftarAkunCore;
use App\Foundation\FinancePosting\ModuleServices\PenerbitPostingCore;
use App\Foundation\FinancePosting\ModuleServices\SetelanPostingFinanceCore;
use App\Foundation\FinancePosting\Support\PostingAccountResolverRegistry;
use App\Foundation\FiscalCalendar\ModuleServices\KalenderFiskalCore;
use App\Foundation\NumberSequence\ModuleServices\PenerbitNomorCore;
use App\Foundation\UnitOfMeasure\ModuleServices\DaftarSatuanCore;
use App\Foundation\Vendor\ModuleServices\DaftarVendorCore;
use App\Foundation\Workflow\ModuleServices\MesinWorkflowCore;
use App\Platform\Access\Support\LinkedWorkerResolverRegistry;
use App\Platform\Attachments\Support\AttachmentRecordTypeRegistry;
use App\Platform\ChangeLog\ModuleServices\ChangeHistoryCore;
use App\Platform\ChangeLog\Support\ChangeLogValueResolverRegistry;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Modules\Contracts\ChangeHistory;
use App\Platform\Modules\Contracts\ChangeLogValueResolvers;
use App\Platform\Modules\Contracts\DaftarAkun;
use App\Platform\Modules\Contracts\DaftarLaporan;
use App\Platform\Modules\Contracts\DaftarSatuan;
use App\Platform\Modules\Contracts\DaftarVendor;
use App\Platform\Modules\Contracts\DirektoriOrganisasi;
use App\Platform\Modules\Contracts\KalenderFiskal;
use App\Platform\Modules\Contracts\KonteksPermintaan;
use App\Platform\Modules\Contracts\KonteksTenant;
use App\Platform\Modules\Contracts\LinkedWorkerResolvers;
use App\Platform\Modules\Contracts\ListExportSources;
use App\Platform\Modules\Contracts\MesinWorkflow;
use App\Platform\Modules\Contracts\PelaksanaUntukTenant;
use App\Platform\Modules\Contracts\PenerbitNomor;
use App\Platform\Modules\Contracts\PenerbitPosting;
use App\Platform\Modules\Contracts\PostingAccountResolvers;
use App\Platform\Modules\Contracts\PresisiMataUang;
use App\Platform\Modules\Contracts\ReportFormatter;
use App\Platform\Modules\Contracts\SetelanPostingFinance;
use App\Platform\Modules\ModuleServices\KonteksTenantPermintaan;
use App\Platform\Organization\ModuleServices\DirektoriOrganisasiCore;
use App\Platform\Reporting\ModuleServices\ReportFormatterCore;
use App\Platform\Reporting\Support\DaftarLaporanModul;
use App\Platform\Reporting\Support\ListExportRegistry;
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
        // Vendor milik Core (party berperan vendor per entitas legal), dipilih di dokumen
        // penerimaan module dan disalin nomor serta namanya ke posting saat terbit.
        DaftarVendor::class => DaftarVendorCore::class,
        // Feed posting finance: module menerbitkan jurnalnya di dalam transaksi dokumen sumbernya,
        // dan memakai pratinjau yang sama untuk menampilkan masalah sebelum konfirmasi.
        PenerbitPosting::class => PenerbitPostingCore::class,
        // Laporan: layar pratinjau module memformat nilai bertipe (`money`, `date`, …) dengan
        // aturan yang sama dengan renderer Core, supaya layar dan hasil cetak tidak berbeda.
        ReportFormatter::class => ReportFormatterCore::class,
        // Log perubahan: module membuka riwayat record miliknya sesudah memeriksa haknya sendiri.
        ChangeHistory::class => ChangeHistoryCore::class,
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
        // Feed posting finance: posting yang dibentuk ulang membaca akunnya dari pemetaan module
        // yang berlaku sekarang, supaya Validasi ulang dapat melepas posting yang tertahan karena
        // pemetaannya dulu kosong.
        PostingAccountResolvers::class => PostingAccountResolverRegistry::class,
        // Log perubahan: nilai mentah (ULID, kode status) diterjemahkan pemilik tabelnya menjadi nama.
        ChangeLogValueResolvers::class => ChangeLogValueResolverRegistry::class,
        // Lampiran dokumen: pemilik tabel menjawab hak atas record induknya dengan aturannya sendiri.
        AttachmentRecordTypes::class => AttachmentRecordTypeRegistry::class,
        // Layar anggota: module pemilik data pekerja menjawab pekerja yang tertaut ke keanggotaan tenant.
        LinkedWorkerResolvers::class => LinkedWorkerResolverRegistry::class,
        // Ekspor daftar di layar (K-27): module pemilik daftar membaca barisnya dengan hak dan kebijakan
        // data yang sama dengan layarnya; Core hanya mengantrekan dan menulis berkasnya.
        ListExportSources::class => ListExportRegistry::class,
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
