<?php

namespace Modules\Apperp\ManagementAset\Support;

use Illuminate\Support\Str;

/**
 * Induk satu master pada rantai klasifikasi aset. Rantai ini adalah struktur domain
 * milik Management Aset, bukan organization hierarchy CoreERP.
 */
final readonly class MasterParent
{
    public function __construct(
        public string $table,
        public string $column,
        public string $relation,
        public string $label,
        public bool $required = true,
    ) {}

    /** Kunci induk pada payload API, misalnya `pabrikan_aset`. */
    public function payloadKey(): string
    {
        return Str::beforeLast($this->column, '_id');
    }

    /**
     * Induk yang sudah diarsipkan tetap ditampilkan agar asal data anak tidak hilang.
     *
     * @return array<string, callable>
     */
    public function eagerLoad(): array
    {
        return [$this->relation => fn ($query) => $query->withTrashed()];
    }
}
