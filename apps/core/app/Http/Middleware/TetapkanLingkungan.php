<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Support\Pusat\AlamatLingkungan;
use App\Support\Pusat\LingkunganAktif;
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
 * persis seperti sebelum middleware ini ada. Selama `coreerp.domain_dasar` kosong, ia bahkan tidak
 * pernah menyala sama sekali.
 *
 * Yang **ditolak** hanya alamat yang berbentuk alamat lingkungan tetapi tidak menunjuk lingkungan
 * yang sah. Itu keadaan yang berbeda: seseorang sedang mencoba masuk ke tempat yang tidak ada,
 * sudah dihapus, atau belum selesai disiapkan. Menerimanya diam-diam dan melayaninya dengan
 * database bawaan adalah cara termurah memperlihatkan data tenant lain.
 *
 * **404, bukan 403.** Keberadaan sebuah lingkungan adalah informasi: `pelanggan-a.demo.contoh.co.id`
 * yang menjawab 403 memberi tahu penanya bahwa pelanggan A memang punya demo.
 *
 * ## Yang BELUM dikerjakan di sini, dan ditulis supaya tidak dikira sudah
 *
 * Middleware ini **belum memindahkan koneksi database**. Ia mengikat identitas lingkungannya
 * sehingga bendera sambungan keluar, spanduk, dan kelak pemilih koneksi punya satu sumber yang
 * sama. Pemindahan koneksinya menuntut `PenjagaKoneksi` beserta jalur gagal-tertutupnya, dan itu
 * pekerjaan tersendiri: sebuah permintaan yang dirutekan ke database yang salah jauh lebih
 * berbahaya daripada permintaan yang tidak dirutekan sama sekali.
 */
class TetapkanLingkungan
{
    public function handle(Request $request, Closure $next): Response
    {
        $alamat = AlamatLingkungan::dariHost($request->getHost());

        if ($alamat === null) {
            // Di luar domain kita — bukan urusan kita, lewat. Ini jalur yang dilalui on-prem,
            // lingkungan lokal, dan seluruh test yang ada.
            if (! AlamatLingkungan::dibawahDomain($request->getHost())) {
                return $next($request);
            }

            // **Di bawah** domain kita tetapi tidak terurai. Itu keadaan yang berbeda: dengan DNS
            // wildcard, setiap label yang pernah diketik siapa pun sampai ke sini, dan menyajikan
            // aplikasi pangkal di sana berarti aplikasi kita dapat disajikan dari alamat mana saja
            // yang dikarang orang. Label yang memang bukan lingkungan — konsol, pemasaran — sudah
            // dikecualikan lebih dulu di `AlamatLingkungan`, dan jatuh ke cabang di atas.
            abort(404);
        }

        $lingkungan = Environment::query()
            ->whereHas('tenant', fn ($q) => $q->where('slug', $alamat->tenant))
            ->where('slug', $alamat->lingkungan)
            ->where('kind', $alamat->jenis)
            ->whereNull('deleted_at')
            ->first();

        // Hanya `active` yang boleh dirutekan. Lingkungan yang sedang disiapkan, sedang disalin,
        // atau bermasalah adalah lingkungan yang **tidak dapat dimasuki** — bukan lingkungan yang
        // dimasuki lalu ternyata setengah jadi.
        if (! $lingkungan instanceof Environment || $lingkungan->status !== 'active') {
            abort(404);
        }

        $request->attributes->set('coreerp.environment', $lingkungan);
        app()->instance(LingkunganAktif::KUNCI, $lingkungan->id);

        return $next($request);
    }
}
