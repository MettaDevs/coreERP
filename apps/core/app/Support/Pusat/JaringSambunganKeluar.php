<?php

declare(strict_types=1);

namespace App\Support\Pusat;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Lapis terakhir: panggilan keluar yang tidak dijaga siapa-siapa berhenti di sini.
 *
 * Tiga titik sudah menjaga dirinya sendiri — penerbit event, pengirim laporan ke Discord, dan
 * perender PDF yang sengaja dibiarkan lewat. Yang ditangkap kelas ini adalah titik keempat:
 * panggilan yang **belum ditulis siapa pun**, dari module yang mendarat besok dan lupa
 * mendeklarasikan pelucutannya. Ia menangkapnya dengan **gagal**, bukan dengan diam, karena
 * sandbox yang diam-diam menghubungi sistem sungguhan milik pelanggan adalah kerusakan yang
 * tidak meninggalkan jejak di log mana pun.
 *
 * ## Ini diagnostik, bukan batas keamanan
 *
 * Kalimat itu harus dibaca sebelum ada yang mengandalkan kelas ini sebagai pengaman, dan
 * ketiga sifat di bawah diperiksa langsung pada Laravel 13.19, bukan diingat:
 *
 * - **Ia berjalan juga di bawah `Http::fake()`.** Global middleware didorong ke handler stack
 *   lebih dulu daripada stub handler, jadi penolakannya terjadi sebelum jawaban palsu dibuat.
 *   Itu kabar baik untuk test — penjaga ini dapat dibuktikan merah tanpa jaringan sungguhan —
 *   tetapi ia juga berarti penolakan di sini tidak membuktikan apa pun tentang paket jaringan.
 * - **Ia hanya menjangkau facade `Http`.** cURL mentah, `file_get_contents('http://...')`, dan
 *   SDK pihak ketiga yang menyusun client Guzzle-nya sendiri lewat begitu saja. Begitu pula
 *   `new PendingRequest` tanpa factory: konstruktornya menerima daftar global middleware
 *   sebagai argumen, jadi yang tidak lewat factory tidak pernah menerimanya.
 * - **Ia berjalan di dalam proses yang sama dengan kode yang diawasinya.** Apa pun yang dapat
 *   memanggil `Http::swap()` dapat melenyapkannya tanpa jejak.
 *
 * Batas yang sesungguhnya adalah jaringan Docker `internal: true`: ia mengikat apa pun yang
 * keluar dari proses PHP, termasuk kode yang tidak kita kendalikan, dan ia belum ada. Sampai
 * ia berdiri, kelas ini yang menggagalkan lebih awal dengan pesan yang terbaca manusia —
 * itulah seluruh nilainya, dan bukan lebih.
 */
final class JaringSambunganKeluar
{
    public static function pasang(): void
    {
        Http::globalRequestMiddleware(static function (RequestInterface $permintaan): RequestInterface {
            // Diresolusi di dalam closure, bukan ditangkap saat pemasangan. Closure ini hidup
            // selama proses; menangkap satu instance berarti pekerja antrean membawa jawaban
            // environment sebelumnya ke job berikutnya — kesalahan yang tidak pernah gagal,
            // hanya salah.
            $lingkungan = app(LingkunganAktif::class);

            if ($lingkungan->bolehKeluar()) {
                return $permintaan;
            }

            if (self::layananDalam($permintaan->getUri()->getHost())) {
                return $permintaan;
            }

            throw new SambunganKeluarDitolak(
                $lingkungan->alasanPenolakan().' Yang dihentikan: '
                .$permintaan->getMethod().' '.self::tujuan($permintaan).'.'
            );
        });
    }

    /**
     * Layanan yang berada di dalam deployment, dan karena itu bukan "luar".
     *
     * Hari ini isinya satu: service render PDF. Ia stateless, tidak mengenal tenant, dan tidak
     * menyimpan apa pun — memblokirnya tidak melindungi seorang pelanggan pun, sementara ia
     * mematikan pencetakan di setiap sandbox.
     *
     * Yang dibandingkan hanya host, bukan beserta port. Itu longgar dengan sengaja: salah arah
     * di sini berarti pencetakan mati di tempat yang tidak ada bahayanya, dan penjaga ini
     * memang bukan yang memikul beban keamanannya.
     */
    private static function layananDalam(string $host): bool
    {
        $perender = parse_url((string) config('reporting.renderer_url'), PHP_URL_HOST);

        return is_string($perender) && $perender !== '' && strcasecmp($host, $perender) === 0;
    }

    /**
     * Alamat tujuan tanpa query dan tanpa kredensial.
     *
     * Pesan galat berakhir di log dan di SigNoz. Query string adalah tempat token dan nomor
     * identitas paling sering menumpang, jadi ia dibuang di sini alih-alih ikut tercetak di
     * tempat yang dibaca lebih banyak orang daripada permintaannya sendiri.
     */
    private static function tujuan(RequestInterface $permintaan): string
    {
        $alamat = $permintaan->getUri();
        $port = $alamat->getPort();

        return $alamat->getScheme().'://'.$alamat->getHost()
            .($port === null ? '' : ':'.$port)
            .$alamat->getPath();
    }
}
