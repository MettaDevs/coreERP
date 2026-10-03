<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts\Analytics;

/**
 * Dimensi milik Core atau Foundation yang dipakai lebih dari satu module. Labelnya diterjemahkan
 * Core lewat {@see SharedDimensionResolver}, dan dua dataset dari module berbeda dapat digabung menurut
 * nilainya (drill-across, area 14). Menambah kasus berarti menambah resolver di Core; module tidak
 * dapat membuat dimensi bersama.
 *
 * Nilai string-nya dipakai sebagai kunci penggabungan antar dataset; menggantinya memutus widget yang
 * sudah menggabungkan dua dataset.
 */
enum SharedDimension: string
{
    case LegalEntity = 'core.legal-entity';
    case OperatingUnit = 'core.operating-unit';
    /** Id pengguna (`users.id`), bukan id keanggotaan tenant. */
    case User = 'core.user';
    case Vendor = 'foundation.vendor';
    /** Kode mata uang ISO 4217. */
    case Currency = 'foundation.currency';

    /** Nama tampilan bawaan untuk field yang dibuat dari kolom tanpa `FIELD_CAPTIONS`. */
    public function caption(): string
    {
        return match ($this) {
            self::LegalEntity => 'Entitas legal',
            self::OperatingUnit => 'Unit kerja',
            self::User => 'Pengguna',
            self::Vendor => 'Vendor',
            self::Currency => 'Mata uang',
        };
    }
}
