<?php

declare(strict_types=1);

namespace Modules\Apperp\ContohA\Http\Controllers;

use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Apperp\ContohA\Models\Barang;
use Modules\Apperp\ContohB\Models\Rak;

/**
 * Rute contoh. Ia ada supaya penjaga batas punya sesuatu untuk diuji.
 *
 * Dua hal yang ditunjukkan dengan sengaja:
 *
 * 1. Module memanggil Core lewat pemanggilan fungsi biasa, bukan HTTP. `CurrentWorkspace`
 *    adalah kelas Core, dan module boleh memanggilnya karena keduanya satu proses.
 * 2. Setiap query menyaring `tenant_id`. Tidak ada lagi database terpisah yang menahan
 *    kebocoran, jadi satu query yang lupa menyaring membocorkan data seluruh tenant.
 */
final class BarangController
{
    public function index(Request $request, CurrentWorkspace $workspace): JsonResponse
    {
        $membership = $workspace->membership($request);

        if (! $membership) {
            return new JsonResponse(['message' => 'Tidak ada workspace aktif.'], 403);
        }

        return new JsonResponse([
            'data' => Barang::query()
                ->where('tenant_id', $membership->tenant_id)
                ->orderBy('kode')
                ->get(['id', 'kode', 'nama']),
        ]);
    }
}
