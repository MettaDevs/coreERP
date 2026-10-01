<?php

declare(strict_types=1);

namespace App\Support\Observabilitas;

use App\Platform\Environment\Support\ActiveEnvironment;
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
 * pesan di sini terbelah dua — ringkasan di `content`, rinciannya di embed.
 *
 * **Isinya hanya data teknis, bukan laporan utuh (K-18).** Discord layanan pihak ketiga, jadi yang
 * dikirim hanya kelas exception, method dan nama rute, status, `tenant_id`, id laporan, dan tautan ke
 * SigNoz. Pesan exception, SQL beserta nilainya, nama tenant, nama pengguna, alamat IP, dan user agent
 * tidak ikut: semuanya bisa memuat data pribadi. Laporan utuh tetap ada di berkas log dan SigNoz, yang
 * berjalan di server kita sendiri; tautannya membawa pembaca ke sana. Ini menyimpang dari BC, yang
 * hanya mengirim data `SystemMetadata` ke telemetri mana pun, karena SigNoz bukan pihak ketiga.
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
    /** Batas Discord untuk `content` adalah 2000 karakter. */
    private const BATAS_CONTENT = 1900;

    /** Merah, menyamai warna yang dipakai Discord sendiri untuk kegagalan. */
    private const WARNA_MERAH = 0xED4245;

    /**
     * Atribut laporan yang boleh sampai ke Discord (K-18), beserta labelnya. Ini daftar izin, bukan
     * daftar larangan: atribut baru di {@see LaporanKesalahan} tidak ikut terkirim sebelum ditambahkan
     * di sini, dan hanya data teknis yang boleh ditambahkan.
     */
    private const ATRIBUT_TERKIRIM = [
        'exception.type' => 'kesalahan',
        'coreerp.sumber_kesalahan' => 'sumber',
        'http.request.method' => 'method',
        'http.route' => 'rute',
        'http.response.status_code' => 'status',
        'coreerp.tenant_id' => 'tenant',
        'coreerp.laporan_id' => 'laporan',
        'trace_id' => 'jejak',
    ];

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
            if (! app(ActiveEnvironment::class)->outboundAllowed()) {
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
            'content' => self::potong(trim($sebutan.' '.self::ringkasan($atribut)), self::BATAS_CONTENT),
            // Tanpa blok ini `@everyone` di dalam `content` tetap tercetak tetapi tidak
            // membunyikan notifikasi: Discord menuntut izin itu dinyatakan, bukan disimpulkan
            // dari isi pesan. Dinyatakan eksplisit juga berarti teks lain di pesan yang
            // kebetulan memuat "@everyone" tidak bisa menyulut sebutan yang tidak diniatkan.
            'allowed_mentions' => self::izinSebutan($sebutan),
            'embeds' => [array_filter([
                'title' => self::potong((string) ($atribut['exception.type'] ?? 'Kesalahan'), 250),
                'url' => $tautan,
                'description' => self::badan($atribut, $tautan),
                'color' => self::WARNA_MERAH,
            ], static fn (mixed $nilai): bool => $nilai !== null)],
        ];
    }

    /**
     * Ringkasan satu baris untuk `content`: kelas exception dan tempat kejadiannya, tanpa pesannya.
     *
     * @param  array<string, scalar|null>  $atribut
     */
    private static function ringkasan(array $atribut): string
    {
        $tempat = ($atribut['coreerp.sumber_kesalahan'] ?? null) === 'http'
            ? trim(((string) ($atribut['http.request.method'] ?? '')).' '.((string) ($atribut['http.route'] ?? '-')))
            : (string) ($atribut['coreerp.sumber_kesalahan'] ?? '-');

        return ((string) ($atribut['exception.type'] ?? 'Kesalahan')).' @ '.$tempat;
    }

    /**
     * Badan pesan: atribut teknis di dalam blok kode, disusul tautan ke SigNoz.
     *
     * **Blok kodenya bukan hiasan.** Tanpa itu Discord membaca `_` di nama rute dan kelas sebagai
     * markdown.
     *
     * **Tautannya berada di luar blok kode** karena di dalamnya ia tidak bisa diklik. Tanpa alamat
     * SigNoz, pembaca diarahkan ke berkas log dengan id laporannya.
     *
     * @param  array<string, scalar|null>  $atribut
     */
    private static function badan(array $atribut, ?string $tautan): string
    {
        $baris = [];
        foreach (self::ATRIBUT_TERKIRIM as $kunci => $label) {
            $nilai = $atribut[$kunci] ?? null;
            if ($nilai !== null && $nilai !== '') {
                $baris[] = sprintf('%-9s: %s', $label, self::potong((string) $nilai, 200));
            }
        }

        $ekor = $tautan !== null
            ? "\n".self::ekorTautan($atribut, $tautan)
            : "\n(laporan utuh ada di berkas log)";

        return "```\n".implode("\n", $baris)."\n```".$ekor;
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
