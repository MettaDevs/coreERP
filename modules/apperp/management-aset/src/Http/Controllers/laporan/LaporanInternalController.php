<?php

namespace Modules\Apperp\ManagementAset\Http\Controllers\laporan;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Apperp\ManagementAset\Http\Controllers\Controller;
use Modules\Apperp\ManagementAset\Reporting\Layouts\BuiltinLayout;
use Modules\Apperp\ManagementAset\Reporting\ReportContext;
use Modules\Apperp\ManagementAset\Reporting\ReportDataException;
use Modules\Apperp\ManagementAset\Reporting\ReportDefinition;
use Modules\Apperp\ManagementAset\Reporting\ReportRegistry;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Endpoint laporan yang dipanggil Core (`internal/v1/laporan/...`).
 *
 * App ini hanya menyediakan tiga hal: definisi laporan (placeholder dan parameter),
 * berkas layout bawaan yang ikut release, dan dataset. Layout unggahan tenant, antrean
 * ekspor, render, dan halaman-halamannya milik Core; lihat
 * docs/dev/23-document-rendering.md di repository CoreERP.
 *
 * Core memanggil dengan token konteks milik pengguna yang meminta — bukan token
 * service — sehingga permission dan scope organisasi yang ditegakkan di sini persis
 * sama dengan yang berlaku di layar biasa.
 */
class LaporanInternalController extends Controller
{
    public function __construct(private readonly ReportRegistry $registry) {}

    public function show(Request $request, string $kode): JsonResponse
    {
        $definition = $this->definition($request, $kode);

        return response()->json(['data' => [
            'kode' => $definition->code(),
            'nama' => $definition->name(),
            'fields' => $definition->fields(),
            'parameters' => array_keys($definition->parameterRules()),
            'builtin_layouts' => array_map(fn (BuiltinLayout $layout): array => [
                'key' => $layout->key,
                'name' => $layout->name,
                'description' => $layout->description,
                'format' => $layout->format,
            ], $definition->builtinLayouts()),
        ]]);
    }

    public function builtinLayout(Request $request, string $kode, string $key): BinaryFileResponse
    {
        $definition = $this->definition($request, $kode);
        foreach ($definition->builtinLayouts() as $layout) {
            if ($layout->key === $key) {
                $path = $layout->path($definition->code());
                abort_unless(is_file($path), 404, 'Berkas layout bawaan tidak ada pada release ini.');

                return response()->download($path, "{$definition->code()}-{$layout->key}.{$layout->format}");
            }
        }
        abort(404);
    }

    public function dataset(Request $request, string $kode): JsonResponse
    {
        $definition = $this->definition($request, $kode);
        $parameters = validator($request->input('parameter', []), $definition->parameterRules())->validate();

        try {
            $data = $definition->data(ReportContext::fromRequest($request), $parameters);
        } catch (ReportDataException $exception) {
            // Data di luar scope atau tidak ada: pesan yang sama dengan yang dilihat
            // pengguna di layar, dan Core meneruskannya apa adanya ke baris ekspor.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => [
            'fields' => $data->fields,
            'tables' => $data->tables,
            'file_name' => $data->fileName,
        ]]);
    }

    private function definition(Request $request, string $kode): ReportDefinition
    {
        abort_unless($this->registry->has($kode), 404, 'Laporan tidak dikenal.');
        $definition = $this->registry->get($kode);
        // Hak membaca data laporan diperiksa di sini juga, bukan hanya di Core: token yang
        // dibawa Core adalah token pengguna, dan app tidak mempercayai pemeriksaan pihak lain.
        abort_unless(
            in_array($definition->permission(), $request->attributes->get('coreerp.permissions', []), true),
            403,
        );

        return $definition;
    }
}
