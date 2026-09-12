<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Actions\Onboarding\RegisterBusiness;
use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\PembuatanTenantRequest;
use App\Models\Environment;
use App\Models\TenantMembership;
use App\Support\Pusat\SandiSementara;
use Illuminate\Http\JsonResponse;

/**
 * Pintu kedua menuju {@see RegisterBusiness}: operator yang melahirkan tenant bagi pelanggan.
 *
 * Sampai rute ini ada, sebuah tenant hanya dapat lahir dari pendaftaran mandiri — sehingga vendor
 * hanya dapat melayani pelanggan yang kebetulan sudah mendaftar sendiri, kebalikan dari alur
 * jualan yang sebenarnya. Rencananya di `docs/todo/environment-dan-pusat-admin/README.md`.
 *
 * Perhatikan apa yang **tidak** ada di sini: tidak ada pembuatan client, tenant, environment,
 * keanggotaan, role, maupun entitlement. Semuanya milik aksi yang sama yang dipakai layar
 * pendaftaran, dan controller ini hanya menyiapkan kata sandi sementara lalu membacakan hasilnya.
 * Itu bentuk yang diminta PRD dan alasannya keras: dua salinan alur pembuatan tenant adalah dua
 * tempat yang akan menyimpang, dan yang menyimpang di sini rantai izin.
 */
final class PembuatanTenantController extends Controller
{
    public function store(PembuatanTenantRequest $permintaan, RegisterBusiness $daftarkanUsaha): JsonResponse
    {
        $sandiSementara = SandiSementara::buat();

        $pemilik = $daftarkanUsaha->handle([
            'name' => $permintaan->string('nama_admin')->toString(),
            'email' => $permintaan->string('email_admin')->toString(),
            'password' => $sandiSementara,
            'business_name' => $permintaan->string('nama_badan_hukum')->toString(),
            'app_ids' => $permintaan->appIds(),
            'must_change_password' => true,
        ]);

        $keanggotaan = TenantMembership::query()
            ->where('user_id', $pemilik->id)
            ->where('system_role', 'owner')
            ->latest('created_at')
            ->firstOrFail();

        $produksi = Environment::query()
            ->where('tenant_id', $keanggotaan->tenant_id)
            ->where('kind', 'production')
            ->firstOrFail();

        // Satu-satunya kesempatan membaca kata sandi ini. Ia tidak disimpan di mana pun dalam
        // bentuk yang dapat dibaca lagi — yang tersimpan hanya hash-nya, sama seperti kata sandi
        // mana pun — jadi operator yang kehilangan balasan ini harus menempuh jalur lupa sandi,
        // bukan meminta seseorang membacakannya dari database.
        return response()->json([
            'tenant_id' => $keanggotaan->tenant_id,
            'environment_id' => $produksi->id,
            'email' => $pemilik->email,
            'kata_sandi_sementara' => $sandiSementara,
        ], 201);
    }
}
