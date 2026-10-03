<?php

declare(strict_types=1);

namespace App\Platform\Analytics\Query;

/**
 * Rentang waktu query: field waktu (bawaan field waktu utama dataset) dan rentangnya, berupa token
 * relatif seperti `@this_month` atau ekspresi tanggal sintaks BC. Bagian dari bentuk query yang
 * dibekukan area 0; pembacaan dan token relatifnya milik area 2.
 */
final readonly class TimeRange
{
    public function __construct(
        public string $range,
        public ?string $field = null,
    ) {}

    /** @return array{field: ?string, range: string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'range' => $this->range];
    }
}
