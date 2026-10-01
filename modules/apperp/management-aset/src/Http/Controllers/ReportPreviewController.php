<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use App\Platform\Modules\Contracts\ReportFormatter;
use App\Platform\Modules\Contracts\RequestContext;
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
 *
 * Nilai bertipe (uang, persen, tanggal, bulan) diformat Core lewat {@see ReportFormatter},
 * aturan yang sama dengan yang mengisi dokumen Word, jadi "Rp 20.000.000,00" di layar
 * sama persis dengan yang tercetak.
 */
final class ReportPreviewController extends Controller
{
    public function show(Request $request, string $code, PenyediaLaporan $reports, ReportFormatter $formatter, RequestContext $akses): JsonResponse
    {
        abort_unless($reports->has($code), 404, 'Laporan ini belum tersedia.');
        $context = ReportContext::fromRequest($request, $akses->timezone());

        try {
            $data = $reports->dataset($code, $context->toArray(), $request->query());
            $data = $formatter->display($context->tenantId, $reports->definition($code, $context->toArray())['fields'], $data, $context->timezone);
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
