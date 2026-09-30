<?php

declare(strict_types=1);

namespace App\Support\Retention;

/**
 * Satu tabel yang boleh diretensi, padanan `AddAllowedTable` di BC: tabel, kolom tanggal acuan, masa simpan
 * minimum, dan bawaan. Bawaan dibaca dari config saat dipakai, bukan saat kelas dimuat, supaya `config()` di
 * test dan perubahan env berlaku. Bawaan kosong berarti kebijakan mati sampai tenant menyalakannya.
 */
final readonly class RetentionPolicy
{
    /**
     * @param  'tenant_id'|'sequence_id'  $tenantVia  Kolom yang menentukan tenant. `sequence_id` menelusuri `tenant_number_sequences`.
     * @param  list<array{column: string, values: list<string>, exclude?: bool}>  $filters  Penyaring tambahan atas baris yang boleh dihapus.
     * @param  ?string  $fileColumn  Kolom path berkas di disk laporan yang ikut dihapus bersama barisnya.
     */
    public function __construct(
        public string $code,
        public string $caption,
        public string $table,
        public string $dateColumn,
        public int $minimumDays,
        public ?string $defaultConfigKey,
        public string $tenantVia = 'tenant_id',
        public array $filters = [],
        public ?string $fileColumn = null,
        public bool $tenantConfigurable = true,
    ) {}

    public function defaultDays(): ?int
    {
        return $this->defaultConfigKey === null ? null : (int) config($this->defaultConfigKey);
    }
}
