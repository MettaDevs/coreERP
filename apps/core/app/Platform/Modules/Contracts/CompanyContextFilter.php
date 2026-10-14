<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Konteks kerja perusahaan untuk resource yang menyatakan pemilik perusahaan.
 *
 * Terpisah dari DataPolicyFilter: izin ke A dan B tetap utuh, tetapi pekerjaan biasa
 * di A hanya membaca/menulis A. Kolom datang dari pemilik resource, perusahaan dari
 * konteks tepercaya. Tidak dipakai pada master bersama atau sebagai bukti izin.
 */
final class CompanyContextFilter
{
    public static function apply(Builder $query, string $legalEntityColumn, ?string $legalEntityId): Builder
    {
        return $legalEntityId === null || $legalEntityId === ''
            ? $query->whereRaw('1 = 0')
            : $query->where($legalEntityColumn, $legalEntityId);
    }

    public static function require(?string $activeLegalEntityId, ?string $recordLegalEntityId): void
    {
        if ($activeLegalEntityId === null || $activeLegalEntityId === '') {
            throw ValidationException::withMessages([
                'legal_entity_id' => 'Pilih perusahaan di tempat kerja sebelum melanjutkan.',
            ]);
        }

        if ($recordLegalEntityId !== $activeLegalEntityId) {
            throw ValidationException::withMessages([
                'legal_entity_id' => 'Perusahaan transaksi harus sama dengan perusahaan aktif. Ganti tempat kerja terlebih dahulu.',
            ]);
        }
    }
}
