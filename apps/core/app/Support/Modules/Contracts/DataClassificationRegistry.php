<?php

declare(strict_types=1);

namespace App\Support\Modules\Contracts;

/**
 * Klasifikasi tabel tenant yang tidak punya model Eloquent (K-19). Satu kelas per pemilik: satu untuk
 * Core (`App\Models\UnmodeledTables`), dan satu per module di folder `src/Models` module itu. Bentuk
 * isinya sama dengan model: klasifikasi bawaan tabel, lalu kolom yang menimpanya.
 *
 * Tabel yang punya model diklasifikasi di modelnya sendiri lewat {@see DataClassification}, bukan di
 * sini; `DataClassificationBoundaryTest` menolak tabel yang dinyatakan di dua tempat.
 */
interface DataClassificationRegistry
{
    /**
     * @return array<string, array{default: DataClass, columns?: array<string, DataClass>}>
     */
    public static function tables(): array;
}
