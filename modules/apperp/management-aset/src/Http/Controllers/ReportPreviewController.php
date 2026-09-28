<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Apperp\ManagementAset\Reporting\PenyediaLaporan;
use Modules\Apperp\ManagementAset\Reporting\ReportAccessDeniedException;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use RuntimeException;

/**
 * Endpoint tunggal untuk menampilkan pratinjau data laporan di layar.
 *
 * Datanya diminta lewat {@see PenyediaLaporan::dataset()}, pintu yang sama dengan yang
 * dipakai mesin cetak Core. Izin, validasi parameter, dan penyusunan dataset tidak ditulis
 * ulang di sini, sehingga baris di layar selalu identik dengan hasil ekspor Excel/PDF dan
 * aturan yang kelak ditambahkan pada jalur cetak otomatis berlaku juga di layar.
 */
final class ReportPreviewController extends Controller
{
    public function show(Request $request, string $code, PenyediaLaporan $reports): JsonResponse
    {
        abort_unless($reports->punya($code), 404, 'Laporan ini belum tersedia.');

        try {
            $data = $reports->dataset($code, ReportContext::fromRequest($request)->toArray(), $request->query());
        } catch (ReportAccessDeniedException $exception) {
            abort(403, $exception->getMessage());
        } catch (RuntimeException $exception) {
            // Parameter ditolak atau datanya di luar jangkauan pengguna. Pesannya sudah
            // siap dibaca dan sama dengan yang Core tulis pada baris ekspor.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $data]);
    }
}
