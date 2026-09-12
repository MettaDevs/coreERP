<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Actions\Onboarding\RegisterBusiness;
use App\Models\Environment;
use App\Models\TenantAppEntitlement;
use App\Support\AppDependencyGraph;
use App\Support\Modules\ModuleRegistry;
use Throwable;

/**
 * Mengisi sebuah lingkungan dengan module yang memang dibeli tenantnya.
 *
 * ## Kenapa ia perlu ada
 *
 * Sampai aksi ini ada, `environment:siapkan` hanya menjalankan migration Core. Akibatnya sebuah
 * demo lahir dengan skema Core yang lengkap dan **nol tabel module** — bukan kosong, hilang. Yang
 * menemukannya bukan test melainkan pertanyaan pemilik produk, dan itu masuk akal: seluruh test
 * pemasangan module berjalan di database bawaan, satu-satunya tempat yang memang sudah terisi.
 *
 * ## Entitlement yang memutuskan, bukan katalog dan bukan isi folder modules/
 *
 * Katalog berarti platform mengenal produknya; entitlement berarti tenant ini berhak memakainya.
 * Menyiapkan lingkungan dari katalog berarti setiap pelanggan mendapat setiap module yang pernah
 * ditulis siapa pun — termasuk yang tidak ia bayar.
 *
 * Sebaliknya, app yang berhak tetapi **tidak ada** sebagai folder module dilewati tanpa suara.
 * Itu bentuk yang benar: entitlementnya tetap tercatat, tetapi tidak ada apa pun yang bisa dipasang
 * untuknya sampai modulenya benar-benar ada di edisi ini. Aturan yang sama sudah berlaku di
 * {@see RegisterBusiness}, dan dua tempat yang menjawabnya berbeda akan
 * menghasilkan lingkungan yang isinya tidak sama dengan tenantnya.
 *
 * ## Urutannya diserahkan pada grafik dependency
 *
 * {@see InstallModule} menolak module yang dependency-nya belum terpasang, dan urutan entitlement
 * di database tidak menjamin apa pun. `resolveAvailable` mengembalikan urutan topologis — yang
 * dibutuhkan lebih dulu keluar lebih dulu — sehingga daftar yang sama tidak akan kadang berhasil
 * dan kadang gagal tergantung urutan barisnya tertulis.
 */
final class PasangModulYangDibeli
{
    public function __construct(
        private readonly AppDependencyGraph $grafik,
        private readonly ModuleRegistry $registry,
        private readonly InstallModule $pasang,
    ) {}

    /**
     * @return list<string> id module yang benar-benar terpasang di lingkungan ini
     *
     * @throws Throwable Satu module gagal dipasang. Sengaja tidak ditelan — lihat di bawah.
     */
    public function untuk(Environment $lingkungan): array
    {
        $berhak = $this->berhak((string) $lingkungan->tenant_id);

        if ($berhak === []) {
            return [];
        }

        $terpasang = [];

        foreach ($this->grafik->resolveAvailable($berhak) as $id) {
            if ($this->registry->cari($id) === null) {
                continue;
            }

            /*
             * Tidak ada `try`/`catch` di sini, dan itu disengaja.
             *
             * Kegagalan memasang satu module berarti lingkungan ini setengah jadi. Menelannya lalu
             * melanjutkan berarti lingkungan itu tetap naik ke `active` dan dapat dimasuki — dengan
             * satu produk yang menunya ada dan tabelnya tidak. Yang benar adalah berhenti, sehingga
             * pemanggilnya menurunkannya ke `degraded` dan seluruh penyiapan dapat diulang: tiap
             * langkah di jalur ini sanggup melihat pekerjaannya sendiri, jadi pengulangan melewati
             * yang sudah selesai.
             */
            $this->pasang->handle($id, (string) $lingkungan->tenant_id, $lingkungan);

            $terpasang[] = $id;
        }

        return $terpasang;
    }

    /**
     * App yang entitlement-nya berlaku **sekarang**.
     *
     * Berlangganan yang sudah berakhir dan yang belum mulai sama-sama dilewati. Tanpa syarat waktu
     * di sini, sebuah demo yang disiapkan hari ini akan memuat produk yang langganannya habis tahun
     * lalu, dan tidak ada satu pun layar yang memperlihatkan dari mana produk itu datang.
     *
     * @return list<string>
     */
    private function berhak(string $tenantId): array
    {
        $id = TenantAppEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('app_id')
            ->pluck('app_id')
            ->all();

        return array_values(array_unique(array_map(strval(...), $id)));
    }
}
