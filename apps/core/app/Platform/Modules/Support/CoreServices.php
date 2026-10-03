<?php

declare(strict_types=1);

namespace App\Platform\Modules\Support;

use App\Platform\Access\Support\LinkedWorkerResolverRegistry;
use App\Platform\AddressBook\ModuleServices\AddressDirectoryCore;
use App\Platform\Analytics\Datasets\DatasetRegistry;
use App\Platform\Analytics\Datasets\SharedDimensionRegistry;
use App\Platform\Attachments\Support\AttachmentRecordTypeRegistry;
use App\Platform\ChangeLog\ModuleServices\ChangeHistoryCore;
use App\Platform\ChangeLog\Support\ChangeLogValueResolverRegistry;
use App\Platform\Modules\Contracts\AccountDirectory;
use App\Platform\Modules\Contracts\AddressDirectory;
use App\Platform\Modules\Contracts\Analytics\Datasets;
use App\Platform\Modules\Contracts\Analytics\SharedDimensions;
use App\Platform\Modules\Contracts\AttachmentRecordTypes;
use App\Platform\Modules\Contracts\ChangeHistory;
use App\Platform\Modules\Contracts\ChangeLogValueResolvers;
use App\Platform\Modules\Contracts\CurrencyRounding;
use App\Platform\Modules\Contracts\FinancePostingSettings;
use App\Platform\Modules\Contracts\FiscalCalendarDirectory;
use App\Platform\Modules\Contracts\LinkedWorkerResolvers;
use App\Platform\Modules\Contracts\ListExportSources;
use App\Platform\Modules\Contracts\ModuleReportProviders;
use App\Platform\Modules\Contracts\NumberSequenceIssuer;
use App\Platform\Modules\Contracts\OrganizationDirectory;
use App\Platform\Modules\Contracts\PostingAccountResolvers;
use App\Platform\Modules\Contracts\PostingFeed;
use App\Platform\Modules\Contracts\ReportFormatter;
use App\Platform\Modules\Contracts\RequestContext;
use App\Platform\Modules\Contracts\TenantContext;
use App\Platform\Modules\Contracts\TenantRunner;
use App\Platform\Modules\Contracts\UnitOfMeasureDirectory;
use App\Platform\Modules\Contracts\VendorDirectory;
use App\Platform\Modules\Contracts\WorkflowEngine;
use App\Platform\Modules\ModuleServices\TenantContextCore;
use App\Platform\Organization\ModuleServices\OrganizationDirectoryCore;
use App\Platform\Reporting\ModuleServices\ReportFormatterCore;
use App\Platform\Reporting\Support\ListExportRegistry;
use App\Platform\Reporting\Support\ModuleReportProviderRegistry;
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
 *
 * Kelas ini milik Platform, jadi ia hanya mengikat pelaksana milik Platform. Antarmuka yang
 * pelaksananya tinggal di Foundation tetap disebut di sini (`FOUNDATION_CONTRACTS`,
 * `FOUNDATION_SINGLETONS`), tetapi diikat oleh penyedia layanan fitur pemiliknya
 * (`<Fitur>ServiceProvider` di akar folder fitur itu, terdaftar di `bootstrap/providers.php`).
 * Platform tidak pernah menyebut kelas Foundation; arah ini dijaga `LayerDirectionBoundaryTest`.
 */
final class CoreServices
{
    /**
     * Antarmuka yang pelaksananya milik Platform, diikat `bind` di sini.
     *
     * @var array<class-string, class-string>
     */
    public const BINDINGS = [
        OrganizationDirectory::class => OrganizationDirectoryCore::class,
        TenantContext::class => TenantContextCore::class,
        // Berdiri sendiri di samping TenantContext, tidak digabung ke dalamnya. Tenant
        // menjawab "di mana boleh membaca", konteks permintaan menjawab "apa yang boleh
        // dilakukan"; menggabungkannya membuat satu antarmuka punya dua sumber data —
        // sesi untuk yang satu, atribut permintaan untuk yang lain — dan pintu yang
        // jawabannya bergantung pada bagian mana yang dipanggil bukan pintu yang jelas.
        RequestContext::class => ModuleRequestContext::class,
        // Satu-satunya pintu module untuk menjalankan sesuatu di luar permintaan HTTP:
        // perintah artisan, pekerja antrean, dan test yang memanggil layanannya langsung.
        // Tanpa ini module harus menyebut kelas Core yang menyimpan tenant aktif, dan
        // batas yang berbunyi satu kalimat langsung runtuh.
        TenantRunner::class => TenantRunnerCore::class,
        // Laporan: layar pratinjau module memformat nilai bertipe (`money`, `date`, …) dengan
        // aturan yang sama dengan renderer Core, supaya layar dan hasil cetak tidak berbeda.
        ReportFormatter::class => ReportFormatterCore::class,
        // Log perubahan: module membuka riwayat record miliknya sesudah memeriksa haknya sendiri.
        ChangeHistory::class => ChangeHistoryCore::class,
        // Buku alamat: module menunjuk tempat beralamat pos (mis. lokasi aset) dan menerjemahkannya
        // menjadi nama dan alamat saat dibaca. Alamat dipelihara di Core, tidak disalin.
        AddressDirectory::class => AddressDirectoryCore::class,
    ];

    /**
     * Antarmuka yang pelaksananya milik Foundation. Diikat `bind` (dibuat baru tiap kali dipakai)
     * oleh penyedia layanan fitur pemiliknya, bukan di sini.
     *
     * @var list<class-string>
     */
    public const FOUNDATION_CONTRACTS = [
        NumberSequenceIssuer::class,
        FiscalCalendarDirectory::class,
        UnitOfMeasureDirectory::class,
        WorkflowEngine::class,
        // Feed posting finance: module yang menyusun jurnal membaca kebijakan penyelesaian
        // dan cutover entitas legal, dan membulatkan nilai dengan presisi yang sama dengan
        // yang dipakai penerbit posting.
        FinancePostingSettings::class,
        CurrencyRounding::class,
        // Feed posting finance: akun milik aplikasi finance pelanggan, dipilih di pemetaan
        // posting module dan dibaca ulang setiap kali posting terbit.
        AccountDirectory::class,
        // Vendor milik Core (party berperan vendor per entitas legal), dipilih di dokumen
        // penerimaan module dan disalin nomor serta namanya ke posting saat terbit.
        VendorDirectory::class,
        // Feed posting finance: module menerbitkan jurnalnya di dalam transaksi dokumen sumbernya,
        // dan memakai pratinjau yang sama untuk menampilkan masalah sebelum konfirmasi.
        PostingFeed::class,
    ];

    /**
     * Kontrak yang **module** penuhi untuk Core, bukan sebaliknya.
     *
     * Dipisahkan dari `BINDINGS` karena cara mengikatnya berbeda dan bedanya menentukan
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
    public const SINGLETON_BINDINGS = [
        ModuleReportProviders::class => ModuleReportProviderRegistry::class,
        // Log perubahan: nilai mentah (ULID, kode status) diterjemahkan pemilik tabelnya menjadi nama.
        ChangeLogValueResolvers::class => ChangeLogValueResolverRegistry::class,
        // Lampiran dokumen: pemilik tabel menjawab hak atas record induknya dengan aturannya sendiri.
        AttachmentRecordTypes::class => AttachmentRecordTypeRegistry::class,
        // Layar anggota: module pemilik data pekerja menjawab pekerja yang tertaut ke keanggotaan tenant.
        LinkedWorkerResolvers::class => LinkedWorkerResolverRegistry::class,
        // Ekspor daftar di layar (K-27): module pemilik daftar membaca barisnya dengan hak dan kebijakan
        // data yang sama dengan layarnya; Core hanya mengantrekan dan menulis berkasnya.
        ListExportSources::class => ListExportRegistry::class,
        // Engine analitik: module menyatakan dataset — tabel, field, measure, kolom kebijakan — dan Core
        // membaca tabelnya lewat definisi itu saja, tanpa menulis nama tabel module (docs/todo/analitik).
        Datasets::class => DatasetRegistry::class,
        // Engine analitik: label dimensi bersama (unit kerja, entitas legal, pengguna, vendor, mata uang).
        // Platform memasang resolver miliknya; fitur Foundation mendaftarkan miliknya dari penyedia layanannya.
        SharedDimensions::class => SharedDimensionRegistry::class,
    ];

    /**
     * Daftar isian milik Foundation. Diikat `singleton` beserta aliasnya oleh penyedia layanan
     * fitur pemiliknya, dengan alasan yang sama seperti `SINGLETON_BINDINGS`.
     *
     * @var list<class-string>
     */
    public const FOUNDATION_SINGLETONS = [
        // Feed posting finance: posting yang dibentuk ulang membaca akunnya dari pemetaan module
        // yang berlaku sekarang, supaya Validasi ulang dapat melepas posting yang tertahan karena
        // pemetaannya dulu kosong. Diikat `FinancePostingServiceProvider`.
        PostingAccountResolvers::class,
    ];

    /**
     * Seluruh antarmuka yang dibuat baru tiap kali dipakai, dari lapis mana pun pelaksananya.
     *
     * @return list<class-string>
     */
    public static function contracts(): array
    {
        return [...array_keys(self::BINDINGS), ...self::FOUNDATION_CONTRACTS];
    }

    public static function register(Application $app): void
    {
        foreach (self::BINDINGS as $contract => $implementation) {
            $app->bind($contract, $implementation);
        }

        foreach (self::SINGLETON_BINDINGS as $contract => $implementation) {
            $app->singleton($implementation);
            $app->alias($implementation, $contract);
        }
    }
}
