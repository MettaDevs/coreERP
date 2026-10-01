<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Support\Rendering;

use App\Platform\Reporting\Support\ReportData;

/**
 * "Excel (data saja)" untuk laporan mana pun (K-26), padanan *Microsoft Excel Document (data only)* pada
 * Send to Business Central: dataset laporan apa adanya, tanpa layout dan tanpa kop.
 *
 * Satu lembar per tabel dataset — judul kolom dari label placeholder pada definisi laporan, urut seperti
 * definisinya — lalu satu lembar "Keterangan" untuk nilai tunggal (filter yang dipakai, total, tanggal
 * cetak). Nilai bertipe ditulis sebagai angka dan tanggal asli lewat {@see TypedSheetWriter}, jadi kolom
 * uang dapat langsung dijumlah dan tanggal dapat diurutkan.
 *
 * Yang ditulis hanya placeholder yang dinyatakan definisi laporan: dataset adalah kontrak, dan kolom yang
 * tidak dinyatakan tidak punya label yang dapat dibaca pengguna.
 */
final class DataOnlyWorkbook
{
    /**
     * @param  list<array<string, mixed>>  $definitions  `fields` dari definisi laporan.
     */
    public function render(ReportData $data, array $definitions): RenderedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'coreerp-data-');
        if ($path === false) {
            throw new RenderException('Berkas sementara untuk ekspor tidak dapat dibuat.');
        }
        $file = new RenderedFile($path, 'xlsx');
        $writer = new TypedSheetWriter('xlsx', $path);

        try {
            $tables = $this->tableColumns($data, $definitions);
            foreach ($tables as $table => $columns) {
                $rows = $data->tables[$table] ?? [];
                if (count($rows) + 1 > TypedSheetWriter::XLSX_MAX_ROWS) {
                    throw new RenderException(sprintf(
                        'Data terlalu besar untuk satu lembar Excel (%d baris; batas %d). Persempit filternya.',
                        count($rows),
                        TypedSheetWriter::XLSX_MAX_ROWS - 1,
                    ));
                }
                $writer->sheet(count($tables) === 1 ? 'Data' : $table);
                $writer->header(array_values($columns));
                $formats = array_map(fn (string $key) => $data->formats[$table.'.'.$key] ?? null, array_keys($columns));
                foreach ($rows as $row) {
                    $writer->row(array_map(fn (string $key) => $row[$key] ?? null, array_keys($columns)), $formats);
                }
            }

            $single = array_values(array_filter($definitions, fn (array $field): bool => ($field['table'] ?? null) === null
                && ! str_starts_with((string) ($field['key'] ?? ''), 'kop.')));
            if ($single !== []) {
                $writer->sheet('Keterangan');
                $writer->header(['Keterangan', 'Nilai']);
                foreach ($single as $field) {
                    $key = (string) $field['key'];
                    $writer->row([(string) ($field['label'] ?? $key), $data->fields[$key] ?? null], [null, $data->formats[$key] ?? null]);
                }
            }
            $writer->close();
        } catch (\Throwable $exception) {
            $file->cleanup();

            throw $exception;
        }

        return $file;
    }

    /**
     * Kolom per tabel: kunci kolom (tanpa awalan tabel) ke labelnya, urut seperti definisi. Tabel yang
     * tidak menyatakan satu kolom pun memakai kunci baris pertamanya sebagai judul.
     *
     * @param  list<array<string, mixed>>  $definitions
     * @return array<string, array<string, string>>
     */
    private function tableColumns(ReportData $data, array $definitions): array
    {
        $tables = [];
        foreach ($definitions as $field) {
            $table = $field['table'] ?? null;
            $key = (string) ($field['key'] ?? '');
            if (! is_string($table) || ! str_starts_with($key, $table.'.')) {
                continue;
            }
            $tables[$table][substr($key, strlen($table) + 1)] = (string) ($field['label'] ?? $key);
        }
        foreach ($data->tables as $table => $rows) {
            if (! isset($tables[$table])) {
                $first = $rows[0] ?? [];
                $tables[$table] = array_combine(array_keys($first), array_keys($first));
            }
        }

        return $tables;
    }
}
