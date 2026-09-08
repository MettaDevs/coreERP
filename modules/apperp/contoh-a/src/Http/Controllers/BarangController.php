<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Http\Controllers;

use App\Support\Modules\Contracts\KonteksTenant;
use App\Support\Modules\Contracts\PenerbitNomor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Barang;

/**
 * Rute contoh. Ia ada supaya penjaga batas punya sesuatu untuk diuji.
 *
 * Tiga hal yang ditunjukkan dengan sengaja:
 *
 * 1. Module memanggil Core lewat kontrak, bukan lewat kelas Core langsung. `KonteksTenant`
 *    dan `PenerbitNomor` adalah dua dari enam pintu resmi yang didaftar `CoreServices`;
 *    menyentuh kelas Core di luar daftar itu ditolak penjaga batas.
 * 2. Setiap query menyaring `tenant_id`. Tidak ada lagi database terpisah yang menahan
 *    kebocoran, jadi satu query yang lupa menyaring membocorkan data seluruh tenant.
 * 3. Nomor diterbitkan **di dalam** transaksi dokumen. Ini keuntungan yang membenarkan
 *    seluruh pemindahan: dokumen gagal, nomornya ikut batal, tidak ada lompatan nomor yang
 *    harus dijelaskan ke pemeriksa.
 */
final class BarangController
{
    public function index(KonteksTenant $konteks): JsonResponse
    {
        return new JsonResponse([
            'data' => Barang::query()
                ->where('tenant_id', $konteks->tenantId())
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
        ]);
    }

    public function store(KonteksTenant $konteks, PenerbitNomor $penerbit): JsonResponse
    {
        $tenantId = $konteks->tenantId();

        $nomor = $penerbit->terbitkan(
            ['tenant_id' => $tenantId, 'app_id' => 'contoh-a'],
            'contoh-a.barang',
            (string) Str::ulid(),
        );

        $barang = Barang::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'kode' => $nomor['number'],
            'nama' => 'Barang baru',
        ]);

        return new JsonResponse(['data' => ['id' => $barang->id, 'kode' => $barang->kode]], 201);
    }
}
