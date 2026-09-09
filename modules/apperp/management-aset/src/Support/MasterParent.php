<?php

namespace Modules\Apperp\ManagementAset\Support;

use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
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
     * Yang dibuang scope soft delete-nya, bukan `withTrashed()` — keduanya persis sama
     * (`withTrashed()` memang macro yang membuang scope ini), tetapi `withTrashed()` tidak
     * terdefinisi pada `Relation`, dan menyempitkan parameternya melanggar kontravariansi
     * `with()`.
     *
     * @return array<string, Closure(Relation<*, *, *>): mixed>
     */
    public function eagerLoad(): array
    {
        return [$this->relation => fn (Relation $query) => $query->withoutGlobalScope(SoftDeletingScope::class)];
    }
}
