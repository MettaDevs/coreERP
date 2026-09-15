<?php

declare(strict_types=1);

namespace App\Support\ControlPlane;

use App\Models\Environment;
use App\Support\Modules\TenantScope;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Menjawab satu pertanyaan: **boleh tidak konteks ini menghubungi dunia luar?**
 *
 * Ia sengaja hanya menjawab itu. Titik-titik yang menegakkannya — penerbit event, pengirim laporan
 * kesalahan, dan jaring HTTP global — tidak perlu tahu apa pun tentang environment; mereka cukup
 * bertanya. Dengan begitu menambah titik keempat kelak tidak menuntut pengetahuan baru, hanya satu
 * pertanyaan yang sama.
 *
 * ## Cara ia tahu
 *
 * Dua jalan, berurutan:
 *
 * 1. **Binding eksplisit.** Siapa pun yang tahu persis environment mana yang sedang dikerjakan —
 *    kelak perintah artisan, job antrean, dan scheduler — mengikatnya pada `KUNCI`. Ini jalan yang
 *    benar, dan ia yang akan menjadi satu-satunya jalan.
 * 2. **Diturunkan dari tenant aktif.** Selama satu tenant baru punya satu environment, tenant yang
 *    terikat sudah cukup untuk menemukannya.
 *
 * ## Ini BUKAN batas keamanan, dan itu harus jelas sebelum ada yang mengandalkannya
 *
 * Penegakannya lewat `Http::globalRequestMiddleware`, dan lapisan itu punya dua lubang yang
 * terbukti dari kode framework, bukan dugaan:
 *
 * - Ia hanya menjangkau panggilan yang lewat facade `Http`. cURL mentah, client Guzzle yang
 *   dibangun sendiri oleh sebuah SDK, dan `file_get_contents('http://...')` lewat begitu saja.
 * - Daftar global middleware diserahkan lewat konstruktor `PendingRequest`, jadi `new
 *   PendingRequest` tanpa factory lahir tanpa satu pun dari daftar itu. Begitu pula `Http::swap()`,
 *   yang mengganti factory-nya sekalian. Keduanya API publik, dan keduanya dapat dipakai satu paket
 *   pihak ketiga dari dalam proses yang sama tanpa meninggalkan jejak.
 *
 * (Versi terdahulu docblock ini menyebut `Http::withoutGlobalConfiguration()` sebagai jalan
 * pintasnya. Method itu tidak ada — diperiksa pada `Factory.php` dan `PendingRequest.php` Laravel
 * 13.19. Kesimpulannya tidak berubah, tetapi sebuah lubang yang ditulis dari ingatan dan bukan dari
 * kodenya tidak layak dipercaya orang berikutnya.)
 *
 * Batas yang sesungguhnya adalah **isolasi jaringan container** (`internal: true`): ia mengikat
 * apa pun yang keluar dari proses PHP, termasuk kode yang tidak kita kendalikan. Kelas ini
 * lapisan diagnostik di atasnya — ia menggagalkan lebih awal dengan pesan yang terbaca manusia,
 * alih-alih timeout jaringan yang membingungkan.
 *
 * ## Kenapa tidak tahu berarti boleh
 *
 * Prinsip yang benar untuk sebuah batas adalah kebalikannya: gagal membaca berarti terisolasi.
 * Di sini sengaja tidak, dan alasannya harus dipahami sebelum seseorang membaliknya:
 *
 * - Hari ini setiap environment yang ada berjenis `production` — dijamin constraint database,
 *   bukan harapan. Tidak ada satu pun keadaan yang seharusnya ditolak.
 * - Rute Core yang bukan milik module berjalan tanpa tenant terikat. Menolak di sana berarti
 *   mematikan pelaporan kesalahan justru pada permintaan yang paling butuh dilaporkan — penjaga
 *   yang menelan laporan tentang kegagalan adalah penjaga yang memperburuk keadaan.
 *
 * **Aturan yang mengikat, dan ia yang membuat pilihan di atas sah:** tidak boleh ada satu pun
 * environment non-produksi dibuat sebelum dua hal berdiri — isolasi jaringan untuk environment
 * itu, dan kewajiban job serta perintah membawa id environment sehingga yang tidak membawanya
 * melempar. Selama keduanya belum ada, satu-satunya jenis yang boleh lahir adalah `production`.
 *
 * ## Kenapa ia tidak pernah melempar
 *
 * Salah satu pemanggilnya adalah pengirim laporan kesalahan, dan ia berjalan justru ketika ada yang
 * sudah salah — kadang database itu sendiri. Penentu yang melempar di sana akan menelan laporan
 * yang seharusnya terkirim, lalu menggantinya dengan laporan tentang dirinya sendiri.
 */
class ActiveEnvironment
{
    /** Kunci container untuk id environment yang sedang dikerjakan. */
    public const KEY = 'coreerp.environment.id';

    private ?Environment $memo = null;

    private bool $resolved = false;

    public function __construct(private Container $container) {}

    public function current(): ?Environment
    {
        if ($this->resolved) {
            return $this->memo;
        }

        $this->resolved = true;

        try {
            $this->memo = $this->resolve();
        } catch (Throwable) {
            $this->memo = null;
        }

        return $this->memo;
    }

    /**
     * Boleh tidak konteks ini menghubungi dunia luar.
     *
     * Tidak pernah melempar. Tidak tahu berarti boleh — alasannya ada di docblock kelas.
     */
    public function outboundAllowed(): bool
    {
        // `->`, bukan `?->`. Di sebelah kiri `??` keduanya berperilaku sama — pembacaan properti
        // pada null menghasilkan null, bukan galat — dan analisa statis menolak yang kedua sebagai
        // penjagaan yang tidak menjaga apa pun.
        return $this->current()->outbound_allowed ?? true;
    }

    /**
     * Alasan penolakan dalam bahasa manusia, untuk dipasang pada pesan galat.
     *
     * Pesan yang hanya berbunyi "ditolak" memaksa orang membaca kode untuk tahu sebabnya. Yang ini
     * menyebut environment-nya, sehingga jelas bahwa yang salah bukan kodenya melainkan tempatnya.
     */
    public function refusalReason(): string
    {
        $environment = $this->current();

        if ($environment === null) {
            return 'Sambungan keluar ditolak.';
        }

        return sprintf(
            'Sambungan keluar ditolak: lingkungan "%s" berjenis %s, bukan produksi. '
            .'Email, pengiriman otomatis ke sistem lain, dan laporan terjadwal sengaja dimatikan di sana '
            .'supaya salinan data tidak menghubungi pihak yang sebenarnya.',
            $environment->name,
            $environment->kind,
        );
    }

    /**
     * Melupakan hasil pencarian sebelumnya.
     *
     * Dipakai ketika konteks berpindah di dalam satu proses yang sama — pekerja antrean yang
     * mengambil job berikutnya, atau perintah yang memutari banyak environment.
     */
    public function lupakan(): void
    {
        $this->memo = null;
        $this->resolved = false;
    }

    /**
     * Lingkungan yang berjalan di server klien tidak pernah menjadi jawabannya.
     *
     * Di server ini ia bukan tempat kerja siapa pun, jadi pekerjaan yang sedang berjalan di sini
     * bukan pekerjaannya — bahkan ketika tenantnya sama. Tanpa saringan, turunan dari tenant memilih
     * produksi server klien itu lebih dulu, lalu menjawab "boleh keluar" untuk job milik demo tenant
     * yang sama yang memang ada di server ini. Dengan saringan, demonya yang terpilih, dan jawabannya
     * menolak — sisi yang aman ketika ragu. Tenant yang tidak punya lingkungan lain di sini jatuh ke
     * "tidak tahu", persis seperti tenant tanpa lingkungan.
     */
    private function resolve(): ?Environment
    {
        if ($this->container->bound(self::KEY)) {
            $id = $this->container->get(self::KEY);

            return is_string($id) ? Environment::query()->hostedByProvider()->find($id) : null;
        }

        if (! $this->container->bound(TenantScope::KUNCI)) {
            return null;
        }

        $tenantId = $this->container->get(TenantScope::KUNCI);

        if (! is_string($tenantId)) {
            return null;
        }

        return Environment::query()
            ->hostedByProvider()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN kind = 'production' THEN 0 ELSE 1 END")
            ->first();
    }
}
