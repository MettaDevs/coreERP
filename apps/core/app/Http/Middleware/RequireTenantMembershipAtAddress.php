<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alamat sebuah tenant hanya melayani akun yang aktif menjadi anggota tenant itu.
 *
 * ## Kenapa ini ada
 *
 * `ResolveEnvironment` menentukan lingkungan dari alamat dan menggeser koneksinya, tetapi tidak
 * pernah bertanya siapa yang sedang masuk. Diukur pada 13 September 2026: akun yang hanya anggota
 * tenant B membuka dasbor di alamat tenant A dan menerima 200, dengan workspace B. Di lingkungan
 * yang punya database sendiri, itu berarti workspace B bekerja di atas database A.
 *
 * {@see CurrentWorkspace::memberships()} kini menyaring keanggotaan ke tenant pemilik alamat, jadi
 * workspace yang salah tidak dapat lagi terpilih. Middleware ini menutup sisanya: akun yang di
 * alamat itu tidak punya keanggotaan sama sekali menerima 403, bukan halaman tanpa workspace yang
 * setengah berfungsi.
 *
 * ## Yang sengaja tetap terbuka
 *
 * - **Keluar.** Tanpa itu, akun yang ditolak hanya dapat pergi dengan menghapus cookie.
 * - **Menukarkan undangan.** Itu justru satu-satunya jalan menjadi anggota tenant ini.
 *
 * Tamu tidak disentuh — halaman masuk memang harus tampil di alamat tenant. Penempatan tanpa
 * domain dasar dan alamat pangkal juga tidak: di sana tidak ada tenant pemilik alamat.
 *
 * ## Akun vendor tidak ditolak
 *
 * Pemilik produk memutuskan akses vendor ke tenant pelanggan "bebas dulu", dan akun vendor ditandai
 * `provider_access` — penanda yang sama yang membuka katalog aplikasi dan pemantauan identitas.
 * Menolaknya di sini akan membatalkan keputusan itu diam-diam.
 *
 * Yang **tidak** ikut dibebaskan adalah workspace-nya. Saringan di `CurrentWorkspace` tetap berlaku
 * bagi vendor, jadi vendor yang bukan anggota tenant ini tidak memperoleh workspace tenant lain di
 * alamat ini — ia masuk tanpa workspace. Jalur vendor yang benar-benar bekerja di dalam tenant
 * pelanggan belum ada, dan perlu dirancang tersendiri.
 */
class RequireTenantMembershipAtAddress
{
    /** Rute yang tetap boleh dijangkau akun yang bukan anggota. Alasannya di docblock kelas. */
    private const ALWAYS_REACHABLE = ['logout', 'join', 'join.store', 'api.invitation-redemptions.store'];

    public function __construct(private readonly CurrentWorkspace $workspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->workspace->tenantOfAddress($request) === null || $request->user() === null) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALWAYS_REACHABLE, true)) {
            return $next($request);
        }

        if ($request->user()->providerAccess()->exists()) {
            return $next($request);
        }

        if ($this->workspace->memberships($request)->isEmpty()) {
            abort(403, 'Akun ini bukan anggota aktif tenant pemilik alamat ini.');
        }

        return $next($request);
    }
}
