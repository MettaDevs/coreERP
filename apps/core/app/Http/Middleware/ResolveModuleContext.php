<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use App\Support\DataPolicyAccessResolver;
use App\Support\LaunchableAppCatalog;
use App\Support\Modules\ModuleRequestContext;
use App\Support\Modules\TenantScope;
use App\Support\Observabilitas\LaporanKesalahan;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menyiapkan konteks Core untuk sebuah rute module, tanpa token.
 *
 * App lama memverifikasi token terbitan Core pada setiap permintaan. Di dalam satu proses
 * yang sama, verifikasi itu tidak menambah apa pun: yang menandatangani dan yang memeriksa
 * tanda tangan adalah proses yang sama, jadi yang tersisa hanyalah biaya HMAC dan sebuah
 * masa berlaku lima menit yang memutus sesi di tengah pekerjaan. Yang benar-benar dibutuhkan
 * module adalah isinya — pengguna, tenant, batas organisasi, izin, dan kebijakan data — dan
 * itu semua sudah dipegang Core di memori.
 *
 * Yang ditulis ke atribut permintaan adalah **kunci yang sama persis** dengan yang ditulis
 * middleware app lama. Lihat `ModuleRequestContext` untuk daftar kuncinya dan alasan kenapa
 * bentuknya dipertahankan.
 *
 * Middleware ini menerima id module sebagai parameter dan **tidak boleh dipasang global**.
 * Izin bersifat per app: `permissionsFor` menanyakan izin untuk satu app_id tertentu, dan
 * middleware global tidak tahu ia sedang melayani module yang mana. Yang tahu adalah grup
 * rute module itu sendiri, dan grup itu dimiliki penyedia layanan module.
 */
final class ResolveModuleContext
{
    /**
     * Penanda bahwa permintaan ini sedang dilayani sebuah module, dan module yang mana.
     *
     * Sengaja **tidak** berawalan `coreerp.`. Kunci berawalan itu adalah salinan harfiah
     * dari yang ditulis middleware app lama, daftarnya dijaga test, dan module membacanya
     * langsung; menambah satu kunci baru ke dalam daftar itu berarti mengubah kontrak yang
     * dijaga demi alasan yang sama sekali berbeda. Yang di sini urusan shell, bukan module.
     */
    public const MODULE_AKTIF = 'module.id';

    public function __construct(
        private readonly CurrentWorkspace $workspace,
        private readonly LaunchableAppCatalog $katalog,
        private readonly DataPolicyAccessResolver $kebijakan,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleId): Response
    {
        $membership = $this->workspace->membership($request);

        // 403, bukan 401. Yang bertugas memastikan ada pengguna adalah middleware `auth` di
        // depan; kalau sampai di sini tanpa keanggotaan tenant yang aktif, pengguna memang
        // ada tetapi tidak berada di tenant mana pun — itu soal wewenang, bukan identitas.
        abort_if($membership === null, 403, 'Tidak ada tenant aktif untuk permintaan ini.');

        $izin = $this->katalog->permissionsFor($membership, $moduleId);

        // Nol izin berarti tidak satu pun rantai role -> duty -> privilege -> permission
        // yang berujung ke module ini. Menolak di sini, sekali, lebih aman daripada
        // mengandalkan setiap controller module ingat memeriksa: yang lupa memeriksa akan
        // terbuka diam-diam, dan tidak ada test yang bisa membuktikan ketiadaan lupa.
        abort_if($izin === [], 403, 'Tidak ada izin untuk module ini.');

        $legalEntity = $this->workspace->legalEntity($request, $membership);
        $orgUnit = $this->workspace->operatingUnit($request, $membership);

        // Tenant aktif diikat ke container, bukan hanya ditaruh sebagai atribut permintaan.
        //
        // `TenantScope` membacanya dari sana, dan ia gagal-menutup: tanpa ikatan ini setiap
        // query model module melempar "Query module dijalankan tanpa tenant aktif" dan
        // permintaannya berakhir 500. Lubang ini tidak terlihat sampai ada module sungguhan
        // yang punya model dan rute sekaligus — kedua module contoh hanya menyentuh modelnya
        // dari test yang mengikat tenantnya sendiri.
        //
        // **Satu batas yang harus diketahui sebelum runtime ini dipindah ke Octane atau
        // pekerja yang hidup lama:** ikatan ini menempel pada container aplikasi, dan container
        // itu dibangun ulang per permintaan hanya pada FPM. Di proses yang hidup lama, tenant
        // dari permintaan sebelumnya akan tersisa untuk permintaan berikutnya yang kebetulan
        // tidak melewati middleware ini. Yang membuatnya aman hari ini adalah model
        // penyajiannya, bukan kodenya — jadi pindah ke Octane menuntut ikatan ini dibereskan
        // lebih dulu, bukan sesudahnya.
        app()->instance(TenantScope::KUNCI, (string) $membership->tenant_id);

        $request->attributes->set(self::MODULE_AKTIF, $moduleId);
        $request->attributes->set(ModuleRequestContext::TENANT_ID, (string) $membership->tenant_id);
        $request->attributes->set(ModuleRequestContext::LEGAL_ENTITY_ID, $legalEntity?->id);
        $request->attributes->set(ModuleRequestContext::ORG_UNIT_ID, $orgUnit?->id);
        $request->attributes->set(ModuleRequestContext::USER_ID, (string) $membership->user_id);
        $request->attributes->set(ModuleRequestContext::PERMISSIONS, $izin);

        /*
         * Nama yang bersanding dengan id di atas, disimpan sekarang karena sekarang gratis.
         *
         * `$membership->tenant` sudah ikut termuat (`CurrentWorkspace::memberships()` memakai
         * `with('tenant')`), dan `$legalEntity` serta `$orgUnit` adalah objek yang baru saja
         * diambil beberapa baris di atas. Membaca namanya di sini tidak menambah satu query
         * pun; membacanya nanti akan menambah tiga.
         *
         * "Nanti" itu bukan hipotesis. Laporan kesalahan membutuhkannya, dan ia sering berjalan
         * justru ketika database sedang tidak bisa ditanya — sehingga satu-satunya nama yang
         * aman baginya adalah nama yang sudah berada di memori sebelum kegagalan terjadi.
         */
        $request->attributes->set(LaporanKesalahan::NAMA_TENANT, $membership->tenant->name);
        $request->attributes->set(LaporanKesalahan::NAMA_LEGAL_ENTITY, $legalEntity?->name);
        $request->attributes->set(LaporanKesalahan::NAMA_ORG_UNIT, $orgUnit?->name);
        $request->attributes->set(LaporanKesalahan::NAMA_PENGGUNA, $request->user()?->name);
        $request->attributes->set(ModuleRequestContext::DATA_POLICIES, $this->kebijakan->resolve($membership));

        /*
         * Kerangka layar — nama app dan menu sidebar-nya — dibagikan dari sini, bukan dari
         * `HandleInertiaRequests`.
         *
         * Alasannya urutan, dan ini sempat menghabiskan waktu: `Inertia\Middleware` memanggil
         * `share()` **sebelum** meneruskan permintaan, sehingga middleware ini belum berjalan
         * saat prop bersama disusun. Prop yang dibaca di sana selalu kosong, dan halamannya
         * tampil tanpa sidebar tanpa satu pun error.
         *
         * Ditutup sebagai closure supaya rute module yang membalas JSON tidak membayar satu
         * query katalog untuk sesuatu yang tidak dipakai; Inertia hanya menyelesaikannya saat
         * benar-benar membangun jawaban Inertia.
         */
        Inertia::share('app', fn (): ?array => $this->katalog->kerangkaModule(
            $membership,
            $moduleId,
            $request->getPathInfo(),
        ));

        return $next($request);
    }
}
