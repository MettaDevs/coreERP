<?php

declare(strict_types=1);

namespace App\Platform\Access\Support;

/**
 * Kode permission layar milik Core sendiri, app `core` di katalog keamanan.
 *
 * Module mendaftarkan empat lapis Dynamics 365 lewat `app.yaml`; layar setup Core dulu tidak, dan dijaga satu
 * penanda owner/admin saja (SEC-22, TODO feed posting 7.4). Sekarang Core ikut rantai yang sama: role -> duty ->
 * privilege -> permission -> entry point, sehingga "boleh memantau posting tanpa boleh mengubah role" dapat
 * diberikan. Katalognya — delapan belas duty *Lihat* dan *Kelola* — ditulis migration
 * `2026_09_25_120100_register_core_security_catalog`, karena migration harus berdiri tanpa kelas aplikasi;
 * kelas ini hanya memegang kode yang dipakai kode aplikasi. Mengubah katalog berarti migration baru, dan kode
 * baru di sini bila kode aplikasi memakainya.
 */
final class CoreSecurityCatalog
{
    public const APP_ID = 'core';

    public const ACCESS_READ = 'core.access.read';

    public const ACCESS_UPDATE = 'core.access.update';

    public const ORGANIZATION_READ = 'core.organization.read';

    public const ORGANIZATION_UPDATE = 'core.organization.update';

    public const NUMBER_SEQUENCE_READ = 'core.number-sequence.read';

    public const NUMBER_SEQUENCE_UPDATE = 'core.number-sequence.update';

    public const REFERENCE_DATA_READ = 'core.reference-data.read';

    public const REFERENCE_DATA_UPDATE = 'core.reference-data.update';

    public const REPORT_LAYOUT_READ = 'core.report-layout.read';

    public const REPORT_LAYOUT_UPDATE = 'core.report-layout.update';

    public const WORKFLOW_READ = 'core.workflow.read';

    public const WORKFLOW_UPDATE = 'core.workflow.update';

    public const FINANCE_SETUP_READ = 'core.finance-setup.read';

    public const FINANCE_SETUP_UPDATE = 'core.finance-setup.update';

    public const VENDOR_READ = 'core.vendor.read';

    public const VENDOR_UPDATE = 'core.vendor.update';

    public const FINANCE_POSTING_READ = 'core.finance-posting.read';

    public const FINANCE_POSTING_PROCESS = 'core.finance-posting.process';

    public const CHANGE_LOG_READ = 'core.change-log.read';

    public const CHANGE_LOG_UPDATE = 'core.change-log.update';

    public const RETENTION_READ = 'core.retention.read';

    public const RETENTION_UPDATE = 'core.retention.update';

    /** Membuat, mengubah, dan mengarsipkan preset laporan yang dibagikan ke semua pengguna tenant (K-25). */
    public const REPORT_PRESET_UPDATE = 'core.report-preset.update';

    /** Duty *Kelola akses*: pemegangnya memegang `ACCESS_UPDATE`, permission yang dijaga `AccessGuards` anti-terkunci. */
    public const ACCESS_MANAGE_DUTY = 'core.access.manage';

    /**
     * Menjalankan analisis bebas di halaman Analisis data dan pembangun widget (KA-14). Katalog analitik lengkap
     * ditulis migration `2026_10_03_120000_register_analytics_security_catalog`; kode lainnya masuk ke sini saat
     * kode aplikasi mulai memakainya.
     */
    public const ANALYTICS_EXPLORE_INVOKE = 'core.analytics.explore.invoke';

    /** Memakai field data pribadi di analitik (KA-14, PQ-04): hanya role Owner yang memegangnya otomatis. */
    public const ANALYTICS_PERSONAL_DATA_READ = 'core.analytics.personal-data.read';

    /** Melihat dasbor dan query tersimpan milik sendiri serta yang bersama, beserta katalog datanya (KA-14, area 6). */
    public const ANALYTICS_DASHBOARD_READ = 'core.analytics.dashboard.read';

    /** Membuat, mengubah, dan mengarsipkan dasbor, widget, dan query tersimpan pribadi (KA-14, area 6). */
    public const ANALYTICS_DASHBOARD_CREATE = 'core.analytics.dashboard.create';

    /** Mengelola dasbor dan query tersimpan bersama, siapa pun pembuatnya (KA-14, area 6). */
    public const ANALYTICS_SHARED_DASHBOARD_UPDATE = 'core.analytics.shared-dashboard.update';

    /** Melihat publikasi analitik tenant beserta klien yang boleh membacanya (KA-14, area 15). */
    public const ANALYTICS_PUBLICATION_READ = 'core.analytics.publication.read';

    /**
     * Membuat, mengubah, menghentikan, mencabut, dan mengambil alih publikasi analitik (KA-14, area 15). Publikasi
     * yang pemiliknya kehilangan permission ini tertahan sampai diambil alih pemegang lain.
     */
    public const ANALYTICS_PUBLICATION_UPDATE = 'core.analytics.publication.update';

    /**
     * Middleware rute untuk satu permission layar Core, misalnya `->middleware(CoreSecurityCatalog::gate(...))`.
     *
     * Permission-nya diberi tanda kutip karena middleware `can` membaca argumen tanpa kutip sebagai nama parameter
     * rute: `can:core,core.access.read` sampai ke gate sebagai `null`, dan layarnya gagal dengan 500 alih-alih 403.
     */
    public static function gate(string $permission): string
    {
        return "can:core,'{$permission}'";
    }
}
