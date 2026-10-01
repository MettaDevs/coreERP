<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Support;

use App\Platform\Modules\Contracts\ListExportSource;
use App\Platform\Modules\Support\LaunchableAppCatalog;
use App\Platform\Reporting\Support\Rendering\RenderedFile;
use App\Platform\Reporting\Support\Rendering\RenderException;
use App\Platform\Reporting\Support\Rendering\TypedSheetWriter;
use App\Platform\Tenant\Models\TenantMembership;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Ekspor daftar di layar module (K-27, TODO 8.4), padanan "Open in Excel" pada list page Business Central:
 * kolom yang tampil dengan judul dan urutannya, filter dan urutan baris yang sedang dipakai, **semua**
 * baris yang cocok — bukan hanya yang sudah dimuat layar — tanpa layout atau template.
 *
 * Core tidak menyentuh tabel module. Barisnya diminta kepada pemilik daftar lewat {@see ListExportSource}
 * di worker antrean, dengan konteks pengguna yang sama dengan laporan: permission baca daftar itu dan
 * kebijakan data organisasinya diterapkan module persis seperti di layar.
 *
 * Baris ditulis satu per satu ke {@see TypedSheetWriter} selagi module membacanya bertahap, jadi memori
 * tetap datar berapa pun jumlahnya. Sampai batas satu lembar Excel hasilnya xlsx bertipe; lebih dari itu
 * CSV, sampai `reporting.list_export_max_csv_rows`.
 */
final class ListExporter
{
    public function __construct(
        private readonly ListExportRegistry $registry,
        private readonly LaunchableAppCatalog $apps,
        private readonly ReportSource $modules,
        private readonly ValueFormats $formats,
    ) {}

    public function source(string $appId, string $listCode): ?ListExportSource
    {
        return $this->registry->find($appId, $listCode);
    }

    /** Boleh mengekspor = boleh melihat: module-nya terbuka bagi pengguna dan ia memegang permission daftarnya. */
    public function canExport(TenantMembership $membership, ListExportSource $source): bool
    {
        return $this->appName($membership, $source) !== null
            && in_array($source->permission(), $this->apps->permissionsFor($membership, $source->moduleId()), true);
    }

    /**
     * Memeriksa permintaan ekspor terhadap daftar milik module: kolom dan urutan harus kolom daftar itu,
     * dan filter harus lolos aturan yang sama dengan endpoint daftarnya.
     *
     * @param  array<string, mixed>  $input
     * @return array{columns: list<array{key: string, header: string}>, sort: array{column: string, direction: string}|null, filters: array<string, mixed>}
     */
    public function normalize(ListExportSource $source, array $input): array
    {
        $labels = array_column($source->columns(), 'label', 'key');
        $columns = [];
        foreach (is_array($input['columns'] ?? null) ? $input['columns'] : [] as $index => $column) {
            $key = is_array($column) ? ($column['key'] ?? null) : null;
            if (! is_string($key) || ! isset($labels[$key])) {
                throw ValidationException::withMessages(["columns.{$index}.key" => ['Kolom ini tidak dapat diekspor dari daftar ini.']]);
            }
            $header = is_string($column['header'] ?? null) ? trim($column['header']) : '';
            $columns[] = ['key' => $key, 'header' => $header !== '' ? mb_substr($header, 0, 150) : (string) $labels[$key]];
        }
        if ($columns === []) {
            throw ValidationException::withMessages(['columns' => ['Pilih sedikitnya satu kolom untuk diekspor.']]);
        }

        $sort = null;
        if (is_array($input['sort'] ?? null)) {
            $column = $input['sort']['column'] ?? null;
            $direction = $input['sort']['direction'] ?? 'asc';
            if (! is_string($column) || ! isset($labels[$column]) || ! in_array($direction, ['asc', 'desc'], true)) {
                throw ValidationException::withMessages(['sort' => ['Urutan ini tidak dapat dipakai untuk daftar ini.']]);
            }
            $sort = ['column' => $column, 'direction' => $direction];
        }

        $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        /** @var array<string, mixed> $valid */
        $valid = validator($filters, $source->filterRules())->validate();

        return ['columns' => $columns, 'sort' => $sort, 'filters' => $valid];
    }

    /**
     * Menulis berkas ekspor satu baris antrean. `$progress` dipanggil setiap seribu baris.
     *
     * @param  callable(int, int): void  $progress  Baris yang sudah ditulis dan jumlah seluruhnya.
     * @return array{file: RenderedFile, rows: int, name: string}
     */
    public function render(stdClass $export, TenantMembership $membership, callable $progress): array
    {
        $appId = (string) $export->app_id;
        $listCode = substr((string) $export->report_code, strlen($appId) + 1);
        $source = $this->source($appId, $listCode)
            ?? throw new RenderException('Daftar ini sudah tidak tersedia pada aplikasi yang terpasang.');
        $appName = $this->appName($membership, $source);
        if ($appName === null || ! $this->canExport($membership, $source)) {
            throw new RenderException('Anda tidak lagi berhak melihat daftar ini.');
        }

        try {
            $request = $this->normalize($source, json_decode((string) $export->parameters, true, flags: JSON_THROW_ON_ERROR) ?: []);
        } catch (ValidationException $exception) {
            throw new RenderException('Permintaan ekspor daftar tidak diterima: '.implode(' ', $exception->validator->errors()->all()), previous: $exception);
        }

        return $this->modules->forModule($appId, $appName, $membership, $export->legal_entity_id, $export->org_unit_id, function (array $context) use ($source, $request, $progress): array {
            $total = $source->count($context, $request['filters']);
            $sheetLimit = min((int) config('reporting.list_export_max_xlsx_rows'), TypedSheetWriter::XLSX_MAX_ROWS - 1);
            $format = $total <= $sheetLimit ? 'xlsx' : 'csv';
            $csvLimit = (int) config('reporting.list_export_max_csv_rows');
            if ($format === 'csv' && $total > $csvLimit) {
                throw new RenderException("Daftar terlalu besar untuk satu ekspor ({$total} baris; batas {$csvLimit}). Persempit filternya.");
            }

            $path = tempnam(sys_get_temp_dir(), 'coreerp-list-');
            if ($path === false) {
                throw new RenderException('Berkas sementara untuk ekspor tidak dapat dibuat.');
            }
            $file = new RenderedFile($path, $format);
            $typed = [];
            foreach ($source->columns() as $column) {
                $typed[$column['key']] = $column;
            }
            $formats = $this->formats->forFields(
                (string) $context['tenant_id'],
                array_values(array_filter($typed, fn (array $column): bool => isset($column['type']))),
                (string) $context['timezone'],
            );
            $keys = array_column($request['columns'], 'key');
            $cellFormats = array_map(fn (string $key): ?ValueFormat => $formats[$key] ?? null, $keys);

            try {
                $writer = new TypedSheetWriter($format, $path);
                $writer->sheet($source->name());
                $writer->header(array_column($request['columns'], 'header'));
                $written = 0;
                foreach ($source->rows($context, $request['filters'], $request['sort']) as $row) {
                    // Baris yang bertambah sesudah dihitung tidak boleh melewati batas format yang sudah dipilih.
                    if ($written >= ($format === 'xlsx' ? $sheetLimit : $csvLimit)) {
                        break;
                    }
                    $writer->row(array_map(fn (string $key): string|int|float|null => $row[$key] ?? null, $keys), $cellFormats);
                    $written++;
                    if ($written % 1000 === 0) {
                        $progress($written, $total);
                    }
                }
                $writer->close();
            } catch (\Throwable $exception) {
                $file->cleanup();

                throw $exception;
            }

            return ['file' => $file, 'rows' => $written, 'name' => $source->name()];
        });
    }

    private function appName(TenantMembership $membership, ListExportSource $source): ?string
    {
        foreach ($this->apps->for($membership) as $app) {
            if ($app['id'] === $source->moduleId()) {
                return (string) $app['name'];
            }
        }

        return null;
    }
}
