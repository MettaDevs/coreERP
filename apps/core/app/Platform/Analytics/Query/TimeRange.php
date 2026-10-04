<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Rentang waktu query: field waktu (bawaan field waktu utama dataset) dan rentangnya, berupa token
 * relatif seperti `@this_month` atau ekspresi tanggal sintaks BC. Bagian dari bentuk query yang
 * dibekukan area 0; pembacaan dan token relatifnya milik area 2.
 *
 * `bounds` hanya terisi untuk token yang artinya bergantung pada data, yaitu tahun fiskal (area 13): hari
 * pertama dan terakhirnya (`Y-m-d`) sesudah dihitung {@see FiscalYearRange} untuk perusahaan query itu. Token
 * kalender tidak butuh isian ini; {@see RelativeRange} menghitungnya dari tanggal hari ini.
 */
final readonly class TimeRange
{
    /** @param  array{0: string, 1: string}|null  $bounds */
    public function __construct(
        public string $range,
        public ?string $field = null,
        public ?array $bounds = null,
    ) {}

    /** @return array{field: ?string, range: string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'range' => $this->range];
    }
}
