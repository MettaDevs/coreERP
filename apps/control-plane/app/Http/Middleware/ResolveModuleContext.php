<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentWorkspace;
use App\Support\DataPolicyAccessResolver;
use App\Support\LaunchableAppCatalog;
use App\Support\Modules\ModuleRequestContext;
use Closure;
use Illuminate\Http\Request;
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

        $request->attributes->set(ModuleRequestContext::TENANT_ID, (string) $membership->tenant_id);
        $request->attributes->set(ModuleRequestContext::LEGAL_ENTITY_ID, $legalEntity?->id);
        $request->attributes->set(ModuleRequestContext::ORG_UNIT_ID, $orgUnit?->id);
        $request->attributes->set(ModuleRequestContext::USER_ID, (string) $membership->user_id);
        $request->attributes->set(ModuleRequestContext::PERMISSIONS, $izin);
        $request->attributes->set(ModuleRequestContext::DATA_POLICIES, $this->kebijakan->resolve($membership));

        return $next($request);
    }
}
