<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Support\ControlPlane\ActiveEnvironment;
use App\Support\ControlPlane\EnvironmentAddress;
use App\Support\ControlPlane\EnvironmentConnection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menentukan lingkungan mana yang sedang dilayani, dari alamat permintaannya.
 *
 * ## Kenapa dari alamat, bukan dari sesi
 *
 * Tiga hal, dan yang ketiga yang paling menentukan:
 *
 * 1. **Peramban memisahkan cookie antar host.** Sesi sandbox tidak pernah dapat membaca sesi
 *    produksi, dan itu dijamin peramban — bukan oleh kode yang harus diingat seseorang.
 * 2. **Alamatnya terbaca manusia.** Operator yang menempelkan tangkapan layar dapat langsung
 *    dilihat sedang berada di mana.
 * 3. **SSO per tenant menuntutnya.** Untuk mengarahkan orang ke penyedia identitas yang benar,
 *    sistem harus tahu tenant mana ini **sebelum** orangnya mengetik apa pun. Sesi belum ada pada
 *    titik itu; alamat sudah.
 *
 * ## Kenapa ia tidak pernah menolak
 *
 * Alamat yang tidak cocok bukan kesalahan. Alamat pangkal, konsol operator, `localhost` polos, dan
 * seluruh penempatan on-prem semuanya jatuh ke sana — dan semuanya memang harus tetap bekerja
 * persis seperti sebelum middleware ini ada. Selama `coreerp.base_domain` kosong, ia bahkan tidak
 * pernah menyala sama sekali.
 *
 * Yang **ditolak** hanya alamat yang berbentuk alamat lingkungan tetapi tidak menunjuk lingkungan
 * yang sah. Itu keadaan yang berbeda: seseorang sedang mencoba masuk ke tempat yang tidak ada,
 * sudah dihapus, atau belum selesai disiapkan. Menerimanya diam-diam dan melayaninya dengan
 * database bawaan adalah cara termurah memperlihatkan data tenant lain.
 *
 * **404, bukan 403.** Keberadaan sebuah lingkungan adalah informasi: `pelanggan-a.demo.erp.contoh.co.id`
 * yang menjawab 403 memberi tahu penanya bahwa pelanggan A memang punya demo.
 *
 * ## Ia sekarang memindahkan koneksi databasenya
 *
 * Sampai 12 September 2026 middleware ini hanya mengikat identitas lingkungannya, dan docblock ini
 * menyebutnya apa adanya sebagai pekerjaan yang belum selesai. Sekarang ia memanggil
 * {@see EnvironmentConnection::useForRequest()}, yang menggeser koneksi bawaan **dan** memaku sisi
 * pusat — sesi, cache, antrean, dan token reset sandi — tetap di tempatnya. Daftar pakuannya beserta
 * akibat melupakan masing-masingnya ada di kelas itu.
 *
 * Ia berjalan sebagai middleware **global**, jadi ia mendahului `StartSession`. Itu bukan
 * kebetulan yang menguntungkan melainkan syarat: penangan sesi dibangun saat sesi pertama kali
 * diminta, dan ia membaca `session.connection` pada saat itu. Digeser sesudahnya berarti pakuannya
 * tidak berlaku.
 *
 * ## Yang BELUM dikerjakan, dan ditulis supaya tidak dikira sudah
 *
 * Lapis gagal-tertutup pertama — koneksi bawaan yang menunjuk database tidak ada, sehingga jalur
 * yang lupa menggeser meledak sendiri — **belum dapat dipasang**. Ia menuntut database pusat
 * benar-benar terpisah, sedangkan hari ini seluruh tabel berbagi satu database dan
 * `coreerp.control_connection` kosong sampai middleware ini mengisinya. Jadi pakuan sisi pusatnya
 * nyata; yang belum nyata hanyalah ledakan otomatis bagi yang lupa.
 *
 * Job antrean juga belum membawa id lingkungannya. Selama hanya lingkungan produksi yang dirutekan
 * — dan produksi tinggal di database bawaan — tidak ada job yang salah alamat. Itu berhenti benar
 * pada hari sebuah demo benar-benar dimasuki orang.
 */
class ResolveEnvironment
{
    public function __construct(private readonly EnvironmentConnection $connections) {}

    public function handle(Request $request, Closure $next): Response
    {
        $address = EnvironmentAddress::fromHost($request->getHost());

        if ($address === null) {
            // Di luar domain kita — bukan urusan kita, lewat. Ini jalur yang dilalui on-prem,
            // lingkungan lokal, dan seluruh test yang ada.
            if (! EnvironmentAddress::isUnderBaseDomain($request->getHost())) {
                return $next($request);
            }

            // **Di bawah** domain kita tetapi tidak terurai. Itu keadaan yang berbeda: dengan DNS
            // wildcard, setiap label yang pernah diketik siapa pun sampai ke sini, dan menyajikan
            // aplikasi pangkal di sana berarti aplikasi kita dapat disajikan dari alamat mana saja
            // yang dikarang orang. Label yang memang bukan lingkungan — konsol, pemasaran — sudah
            // dikecualikan lebih dulu di `EnvironmentAddress`, dan jatuh ke cabang di atas.
            abort(404);
        }

        // Tenant + jenis menunjuk tepat satu lingkungan hidup: `environments_satu_produksi` dan
        // `environments_satu_per_jenis` di database pusat yang menjaminnya, bukan urutan baris.
        //
        // Produksi yang berjalan di server klien disaring di sini, dan jawabannya 404 — bukan 503,
        // apa pun statusnya. Barisnya memang ada, tetapi isinya tidak di server ini: `database_name`
        // yang kosong akan membuat `useForRequest` di bawah melayaninya dari database bersama, yaitu
        // halaman masuk yang menerima akun tenant itu di atas data yang bukan datanya. Alamatnya pun
        // bukan milik server ini lagi; klien membukanya di servernya sendiri.
        $environment = Environment::query()
            ->hostedByProvider()
            ->whereHas('tenant', fn ($q) => $q->where('slug', $address->tenant))
            ->where('kind', $address->kind)
            ->whereNull('deleted_at')
            ->first();

        if (! $environment instanceof Environment) {
            abort(404);
        }

        /*
         * Hanya `active` yang boleh dirutekan. Lingkungan yang sedang disiapkan, sedang disalin,
         * sedang diperbarui, atau bermasalah adalah lingkungan yang **tidak dapat dimasuki** —
         * bukan lingkungan yang dimasuki lalu ternyata setengah jadi.
         *
         * Tetapi kodenya dibedakan, karena 404 dan 503 menjawab pertanyaan yang berbeda. 404
         * berarti "tidak ada", dan itu benar untuk lingkungan yang belum pernah berdiri —
         * keberadaan sebuah demo adalah informasi yang tidak perlu dibocorkan kepada penanya.
         * 503 berarti "ada, sedang tidak melayani", dan itu benar untuk lingkungan yang hidup
         * kemarin: pemiliknya sudah tahu ia ada, jadi menyembunyikannya tidak melindungi apa pun
         * dan membuat gangguan terbaca seperti salah ketik alamat.
         *
         * Pembedanya `schema_migrated_at` — terisi berarti lingkungan itu pernah benar-benar
         * punya skema, dan karena itu pernah dapat dimasuki.
         */
        if ($environment->status !== 'active') {
            abort($environment->schema_migrated_at !== null ? 503 : 404);
        }

        $request->attributes->set('coreerp.environment', $environment);
        app()->instance(ActiveEnvironment::KEY, $environment->id);

        $this->connections->useForRequest($environment);

        return $next($request);
    }
}
