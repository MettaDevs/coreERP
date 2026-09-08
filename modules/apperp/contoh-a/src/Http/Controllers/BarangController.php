<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Http\Controllers;

use App\Support\Modules\Contracts\KonteksPermintaan;
use App\Support\Modules\Contracts\KonteksTenant;
use App\Support\Modules\Contracts\PenerbitNomor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\Apperp\ContohA\Models\Barang;

/**
 * Rute contoh. Ia ada supaya penjaga batas punya sesuatu untuk diuji.
 *
 * Empat hal yang ditunjukkan dengan sengaja:
 *
 * 1. Module memanggil Core lewat kontrak, bukan lewat kelas Core langsung. `KonteksTenant`,
 *    `KonteksPermintaan`, dan `PenerbitNomor` adalah tiga dari pintu resmi yang didaftar
 *    `CoreServices`; menyentuh kelas Core di luar daftar itu ditolak penjaga batas.
 * 2. Penyaringan `tenant_id` tidak ditulis di sini sama sekali. `MilikTenant` yang
 *    menyaring bacaan, mengisi tenant pada baris baru, dan membatalkan penyimpanan yang
 *    ditujukan ke tenant lain. Yang tidak ditulis tidak bisa salah ditulis — dan penyaringan
 *    tangan di sini justru membuat penjaganya tidak terukur, karena query tetap benar walau
 *    penjaganya dicabut.
 * 3. Nomor diterbitkan **di dalam** transaksi dokumen. Ini keuntungan yang membenarkan
 *    seluruh pemindahan: dokumen gagal, nomornya ikut batal, tidak ada lompatan nomor yang
 *    harus dijelaskan ke pemeriksa.
 * 4. Izin diperiksa per entry point di sini, bukan hanya di middleware. Middleware menolak
 *    pengguna yang tidak punya izin apa pun pada module ini; yang membedakan "boleh melihat"
 *    dari "boleh menambah" tetap pemeriksaan di titik pemakaiannya.
 */
final class BarangController
{
    public function index(KonteksPermintaan $akses): JsonResponse
    {
        abort_unless($akses->punyaIzin('contoh-a.barang.read'), 403);

        return new JsonResponse([
            'data' => Barang::query()
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
        ]);
    }

    public function store(KonteksTenant $konteks, KonteksPermintaan $akses, PenerbitNomor $penerbit): JsonResponse
    {
        abort_unless($akses->punyaIzin('contoh-a.barang.create'), 403);

        $tenantId = $konteks->tenantId();

        $nomor = $penerbit->terbitkan(
            ['tenant_id' => $tenantId, 'app_id' => 'contoh-a'],
            'contoh-a.barang',
            (string) Str::ulid(),
        );

        $barang = Barang::query()->create([
            'id' => (string) Str::ulid(),
            'kode' => $nomor['number'],
            'nama' => 'Barang baru',
        ]);

        return new JsonResponse(['data' => ['id' => $barang->id, 'kode' => $barang->kode]], 201);
    }
}
