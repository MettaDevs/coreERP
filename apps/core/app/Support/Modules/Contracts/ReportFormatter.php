<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Nilai laporan bertipe, diformat persis seperti yang akan tercetak.
 *
 * Mesin laporan Core memformat nilai yang menyatakan tipenya pada `fields()` — `money`,
 * `number`, `percent`, `date`, `month` — saat mengisi layout. Layar pratinjau laporan milik
 * module memakai antarmuka ini supaya yang terlihat di layar sama dengan yang tercetak,
 * tanpa module menulis aturan rupiah dan tanggalnya sendiri. Nilai tanpa tipe dikembalikan
 * apa adanya.
 *
 * Kegagalan — tipe yang tidak dikenal, atau mata uang yang presisinya belum disetel —
 * dilempar sebagai `RuntimeException` dengan pesan siap-baca.
 */
interface ReportFormatter
{
    /**
     * @param  list<array<string, mixed>>  $fields  `fields` dari definisi laporan yang sama.
     * @param  array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}  $dataset
     * @return array{fields: array<string, mixed>, tables: array<string, mixed>, file_name: string}
     */
    public function display(string $tenantId, array $fields, array $dataset): array;
}
