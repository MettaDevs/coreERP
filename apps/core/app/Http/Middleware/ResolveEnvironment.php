<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Environment;
use App\Support\ControlPlane\EnvironmentAddress;
use App\Support\ControlPlane\ActiveEnvironment;
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
class ResolveEnvironment
{
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

        $environment = Environment::query()
            ->whereHas('tenant', fn ($q) => $q->where('slug', $address->tenant))
            ->where('slug', $address->environment)
            ->where('kind', $address->kind)
            ->whereNull('deleted_at')
            ->first();

        // Hanya `active` yang boleh dirutekan. Lingkungan yang sedang disiapkan, sedang disalin,
        // atau bermasalah adalah lingkungan yang **tidak dapat dimasuki** — bukan lingkungan yang
        // dimasuki lalu ternyata setengah jadi.
        if (! $environment instanceof Environment || $environment->status !== 'active') {
            abort(404);
        }

        $request->attributes->set('coreerp.environment', $environment);
        app()->instance(ActiveEnvironment::KEY, $environment->id);

        return $next($request);
    }
}
