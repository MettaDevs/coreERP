<?php

declare(strict_types=1);

namespace App\Support\Observabilitas;

use App\Support\Pusat\LingkunganAktif;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Mengirim laporan kesalahan ke satu channel Discord lewat webhook.
 *
 * **Kenapa webhook, bukan bot.** Yang dibutuhkan di sini hanya satu arah: menaruh pesan di
 * satu channel dan menyebut orang. Bot menambah token yang harus dijaga, undangan per server,
 * dan proses gateway — semuanya demi kemampuan yang tidak dipakai. Pindah ke bot nanti cukup
 * mengganti isi {@see self::kirimKe()}; bentuk pesannya tidak ikut berubah.
 *
 * **Sebutan harus berada di `content`, tidak boleh di dalam embed.** Discord hanya menerbitkan
 * notifikasi untuk sebutan yang muncul di `content`; yang ditulis di dalam embed tetap tampil
 * biru dan tetap bisa diklik, tetapi tidak pernah membunyikan apa pun. Ini satu-satunya alasan
 * pesan di sini terbelah dua — ringkasan di `content`, laporan utuh di embed.
 *
 * **Tidak ada jalur yang boleh melempar,** aturan yang sama dengan seluruh isi folder ini.
 * Discord yang mati, webhook yang dicabut, atau jaringan yang diblokir tidak boleh menjadi
 * kegagalan kedua yang menimpa kegagalan pertama.
 *
 * **Di lingkungan yang bukan produksi, kiriman ini ditekan.** Bukan karena laporannya tidak
 * berharga, melainkan karena channel itu dibaca sebagai "ada yang rusak pada pelanggan".
 * Sandbox berisi salinan produksi menghasilkan kesalahan yang persis sama bentuknya, sehingga
 * tanpa penekanan ini satu orang yang sedang mencoba-coba di sandbox membangunkan tim dengan
 * peringatan yang tidak menunjuk apa pun — dan peringatan yang beberapa kali terbukti palsu
 * adalah peringatan yang berikutnya tidak dibaca. Laporannya tetap utuh di berkas log dan di
 * SigNoz; yang hilang hanya dering notifikasinya.
 */
final class PengirimDiscord
{
    /** Batas Discord untuk `content` adalah 2000 karakter, untuk `description` embed 4096. */
    private const BATAS_CONTENT = 1900;

    private const BATAS_EMBED = 3800;

    /** Merah, menyamai warna yang dipakai Discord sendiri untuk kegagalan. */
    private const WARNA_MERAH = 0xED4245;

    public static function kirim(LaporanKesalahan $laporan): void
    {
        try {
            $webhook = self::webhook();

            if ($webhook === null) {
                return;
            }

            // Ditanyakan **sebelum** penjeda disentuh, dan urutannya bukan selera. Penjeda
            // menulis penanda begitu ia meluluskan sebuah laporan; kalau ia berjalan lebih
            // dulu, sandbox membakar jatah kiriman untuk pesan yang memang tidak akan pernah
            // berangkat — dan pada saat yang sama urutan itulah yang membuat penekanan di
            // sini dapat dibedakan dari penolakan jaring global, yang baru bekerja jauh di
            // hilir. Penjaga yang hasilnya tidak dapat dibedakan dari penjaga lain tidak
            // dapat dibuktikan merah.
            if (! app(LingkunganAktif::class)->bolehKeluar()) {
                return;
            }

            if (! PenjedaKiriman::boleh($laporan)) {
                return;
            }

            self::kirimKe($webhook, self::muatan($laporan));
        } catch (Throwable) {
            // Lihat catatan kelas. Kegagalan mengirim tidak pernah menjadi kesalahan kedua.
        }
    }

    private static function webhook(): ?string
    {
        $nilai = config('coreerp.discord.webhook_url');

        return is_string($nilai) && $nilai !== '' ? $nilai : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function muatan(LaporanKesalahan $laporan): array
    {
        $sebutan = (string) config('coreerp.discord.mention', '');
        $atribut = $laporan->keAtribut();
        $tautan = self::tautanSigNoz($atribut);

        return [
            'content' => self::potong(trim($sebutan.' '.self::bersihkan($laporan->ringkasan())), self::BATAS_CONTENT),
            // Tanpa blok ini `@everyone` di dalam `content` tetap tercetak tetapi tidak
            // membunyikan notifikasi: Discord menuntut izin itu dinyatakan, bukan disimpulkan
            // dari isi pesan. Dinyatakan eksplisit juga berarti pesan kesalahan yang kebetulan
            // memuat "@everyone" — misalnya karena ikut terbawa dari data pengguna — tidak
            // bisa menyulut sebutan yang tidak diniatkan.
            'allowed_mentions' => self::izinSebutan($sebutan),
            'embeds' => [array_filter([
                'title' => self::potong((string) ($atribut['exception.type'] ?? 'Kesalahan'), 250),
                'url' => $tautan,
                'description' => self::badan($laporan, $tautan),
                'color' => self::WARNA_MERAH,
            ], static fn (mixed $nilai): bool => $nilai !== null)],
        ];
    }

    /**
     * Badan pesan: laporan di dalam blok kode, disusul tautan ke SigNoz.
     *
     * **Blok kodenya bukan hiasan.** Tanpa itu Discord membaca `#`, `*`, dan `_` di dalam
     * pesan driver sebagai markdown, dan SQL yang gagal berubah bentuk persis ketika ia paling
     * perlu dibaca apa adanya.
     *
     * **Tautannya berada di luar blok kode** karena di dalamnya ia tidak bisa diklik.
     */
    private static function badan(LaporanKesalahan $laporan, ?string $tautan): string
    {
        $ekor = $tautan !== null ? "\n".self::ekorTautan($laporan->keAtribut(), $tautan) : '';
        $ruang = self::BATAS_EMBED - mb_strlen($ekor);
        $teks = self::bersihkan($laporan->keTeks());

        if (mb_strlen($teks) > $ruang) {
            // Dipotong tanpa penanda "(dipotong)". Yang dibutuhkan orang yang membaca ini
            // bukan pemberitahuan bahwa ada yang hilang — ia sudah bisa melihatnya — melainkan
            // jalan menuju yang utuh. Itu tugas tautannya.
            $teks = mb_substr($teks, 0, $ruang);

            if ($ekor === '') {
                $ekor = "\n(laporan utuh ada di berkas log)";
                $teks = mb_substr($teks, 0, $ruang - mb_strlen($ekor));
            }
        }

        return "```\n".$teks."\n```".$ekor;
    }

    /**
     * Membuang hal-hal yang berguna di berkas tetapi menjadi sampah di Discord.
     *
     * Garis pemisah menandai batas antar laporan pada berkas yang ditulis sambung-menyambung;
     * satu pesan Discord sudah menjadi batasnya sendiri, jadi di sini garis itu hanya
     * menghabiskan tempat. Penanda `(dipotong)` juga dibuang: di berkas ia jujur, di sini ia
     * digantikan tautan yang membawa orang ke isi yang lengkap.
     */
    private static function bersihkan(string $teks): string
    {
        $teks = (string) preg_replace('/ … \(dipotong\)/u', '…', $teks);
        $teks = (string) preg_replace('/^[─-]{3,}\R?/mu', '', $teks);

        return trim((string) preg_replace('/\R{3,}/u', "\n\n", $teks));
    }

    /**
     * Baris tautan di bawah laporan.
     *
     * Dua tautan ketika keduanya ada, karena keduanya menjawab pertanyaan yang berbeda:
     * catatan log adalah laporan ini sendiri dengan seluruh atributnya yang bisa disaring,
     * sedangkan jejak memuat **seluruh** rentang permintaan itu — termasuk query yang berhasil
     * sebelum satu yang gagal, yang sering justru itulah yang menjelaskan kenapa.
     *
     * @param  array<string, scalar|null>  $atribut
     */
    private static function ekorTautan(array $atribut, string $tautanCatatan): string
    {
        $ekor = '[Buka catatannya di SigNoz]('.$tautanCatatan.')';
        $jejak = $atribut['trace_id'] ?? null;
        $pangkal = self::pangkalSigNoz();

        if (is_string($jejak) && $jejak !== '' && $pangkal !== null) {
            $ekor .= ' · [Jejak permintaannya]('.$pangkal.'/trace/'.$jejak.')';
        }

        return $ekor;
    }

    /**
     * Tautan ke catatan laporan ini sendiri di penjelajah log SigNoz.
     *
     * Bukan ke halaman penjelajah yang kosong: `coreerp.laporan_id` dicetak sekali per
     * laporan dan ikut terkirim sebagai atribut, jadi saringan atasnya menyisakan tepat satu
     * catatan — yang ini. Itu berlaku juga untuk perintah artisan dan pekerja antrean, yang
     * tidak punya `trace_id` dan karena itu tidak punya jalan lain untuk ditemukan.
     *
     * **Bentuk `compositeQuery` adalah urusan dalam SigNoz, bukan antarmuka yang dijanjikan.**
     * Ia diperiksa langsung pada v0.141.1 dan bisa berubah pada versi berikutnya. Kalau suatu
     * saat tautannya membuka penjelajah tanpa saringan, di sinilah tempat memperbaikinya —
     * dan sementara itu tidak ada yang rusak selain kenyamanan.
     *
     * @param  array<string, scalar|null>  $atribut
     */
    private static function tautanSigNoz(array $atribut): ?string
    {
        $pangkal = self::pangkalSigNoz();

        if ($pangkal === null) {
            return null;
        }

        $id = $atribut['coreerp.laporan_id'] ?? null;

        if (! is_string($id) || $id === '') {
            return $pangkal.'/logs/logs-explorer';
        }

        $kueri = [
            'queryType' => 'builder',
            'builder' => [
                'queryData' => [[
                    'dataSource' => 'logs',
                    'queryName' => 'A',
                    'filter' => ['expression' => sprintf("coreerp.laporan_id = '%s'", $id)],
                    'aggregations' => [['expression' => 'count()']],
                    'expression' => 'A',
                    'disabled' => false,
                ]],
                'queryFormulas' => [],
            ],
        ];

        // Rentangnya sehari, bukan setengah jam seperti bawaan penjelajah. Tautan ini dibuka
        // ketika seseorang sempat membacanya — bisa besok pagi — dan rentang bawaan membuatnya
        // membuka halaman kosong yang terlihat seperti catatannya tidak pernah sampai.
        return $pangkal.'/logs/logs-explorer?relativeTime=1d&compositeQuery='
            .rawurlencode((string) json_encode($kueri));
    }

    private static function pangkalSigNoz(): ?string
    {
        $pangkal = rtrim((string) config('coreerp.signoz_url', ''), '/');

        return $pangkal === '' ? null : $pangkal;
    }

    /**
     * Menyatakan sebutan mana yang boleh berbunyi, dibaca dari konfigurasi — bukan dari pesan.
     *
     * @return array<string, mixed>
     */
    private static function izinSebutan(string $sebutan): array
    {
        $parse = [];

        if (str_contains($sebutan, '@everyone') || str_contains($sebutan, '@here')) {
            $parse[] = 'everyone';
        }

        // `<@123>` menyebut orang, `<@&123>` menyebut role. Keduanya dikumpulkan sebagai
        // daftar id, bukan dilepas lewat `parse`, supaya yang berbunyi persis yang ditulis di
        // konfigurasi dan bukan setiap id yang kebetulan muncul di dalam pesan.
        preg_match_all('/<@!?(\d+)>/', $sebutan, $orang);
        preg_match_all('/<@&(\d+)>/', $sebutan, $peran);

        return array_filter([
            'parse' => $parse,
            'users' => $orang[1],
            'roles' => $peran[1],
        ], static fn (array $nilai): bool => $nilai !== []);
    }

    /**
     * @param  array<string, mixed>  $muatan
     */
    private static function kirimKe(string $webhook, array $muatan): void
    {
        // Batas waktunya pendek dengan sengaja. Pengiriman ini berjalan di dalam penangan
        // kesalahan, artinya ada orang yang sedang menunggu jawaban di ujung sana; Discord
        // yang lambat tidak boleh menahan jawaban itu lebih lama daripada kesalahannya
        // sendiri.
        Http::connectTimeout(2)
            ->timeout(4)
            ->asJson()
            ->post($webhook, $muatan);
    }

    private static function potong(string $teks, int $batas): string
    {
        return mb_strlen($teks) <= $batas ? $teks : mb_substr($teks, 0, $batas - 3).'...';
    }
}
