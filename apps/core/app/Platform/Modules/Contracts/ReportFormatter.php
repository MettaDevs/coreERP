<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

/**
 * Nilai laporan bertipe, diformat persis seperti yang akan tercetak.
 *
 * Mesin laporan Core memformat nilai yang menyatakan tipenya pada `fields()` — `money`,
 * `number`, `percent`, `date`, `month`, `datetime` — saat mengisi layout. Layar pratinjau laporan milik
 * module memakai antarmuka ini supaya yang terlihat di layar sama dengan yang tercetak,
 * tanpa module menulis aturan rupiah dan tanggalnya sendiri. Nilai tanpa tipe dikembalikan
 * apa adanya.
 *
 * Waktu (`datetime`) ditampilkan menurut `$timezone`, zona waktu pengguna yang membuka pratinjau.
 * Module mengambilnya dari konteks laporan (`timezone`), bukan dari jam server atau peramban.
 *
 * Kegagalan — tipe yang tidak dikenal, atau mata uang yang presisinya belum disetel —
 * dilempar sebagai `RuntimeException` dengan pesan siap-baca.
 */
interface ReportFormatter
{
    /**
     * @param  list<array<string, mixed>>  $fields  `fields` dari definisi laporan yang sama.
     * @param  string  $timezone  Zona waktu pengguna, nama IANA; dari `timezone` pada konteks laporan.
     * @param  array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}  $dataset
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    public function display(string $tenantId, array $fields, array $dataset, string $timezone): array;
}
