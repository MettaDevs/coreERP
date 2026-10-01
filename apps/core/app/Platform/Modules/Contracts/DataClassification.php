<?php

declare(strict_types=1);

namespace App\Platform\Modules\Contracts;

use Attribute;

/**
 * Klasifikasi bawaan tabel sebuah model tenant (K-06). Kolom yang berbeda dari bawaan ditulis di
 * konstanta `COLUMN_CLASSIFICATION` pada model yang sama:
 *
 * ```php
 * #[DataClassification(DataClass::CustomerContent)]
 * final class Worker extends Model
 * {
 *     public const COLUMN_CLASSIFICATION = [
 *         'name' => DataClass::EndUserIdentifiableInformation,
 *     ];
 * }
 * ```
 *
 * Atribut ini dibaca juga dari kelas induk, jadi model yang mewarisi kelas dasar berklasifikasi ikut
 * membawanya. Tabel tenant tanpa model dinyatakan di {@see DataClassificationRegistry} (K-19).
 * `DataClassificationBoundaryTest` menolak tabel tenant yang belum diklasifikasi.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DataClassification
{
    public function __construct(public readonly DataClass $default) {}
}
