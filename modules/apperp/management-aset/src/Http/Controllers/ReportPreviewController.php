<?php

declare(strict_types=1);

namespace Modules\Apperp\ManagementAset\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportDataException;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;

/**
 * Endpoint tunggal untuk menampilkan pratinjau data laporan di layar.
 *
 * Query tidak ditulis ulang di sini; seluruh data dibaca langsung dari
 * `ReportDefinition::data()` yang sama dengan yang dipanggil mesin cetak Core,
 * sehingga baris data di layar selalu identik dengan hasil ekspor Excel/PDF.
 */
final class ReportPreviewController extends Controller
{
    public function show(Request $request, string $code, ReportRegistry $registry): JsonResponse
    {
        abort_unless($registry->has($code), 404, "Laporan `{$code}` tidak dikenal.");

        $definition = $registry->get($code);
        $permissions = (array) $request->attributes->get('coreerp.permissions', []);

        abort_unless(
            in_array($definition->permission(), $permissions, true),
            403,
            'Anda tidak memiliki izin untuk membaca data laporan ini.',
        );

        try {
            /** @var array<string, mixed> $validated */
            $validated = validator($request->all(), $definition->parameterRules())->validate();
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Parameter laporan tidak valid: '.implode(' ', $exception->validator->errors()->all()),
                'errors' => $exception->validator->errors()->toArray(),
            ], 422);
        }

        try {
            $context = ReportContext::fromRequest($request);
            $data = $definition->data($context, $validated);
        } catch (ReportDataException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => [
                'fields' => $data->fields,
                'tables' => $data->tables,
                'file_name' => $data->fileName,
                'row_count' => $data->rowCount(),
            ],
        ]);
    }
}
